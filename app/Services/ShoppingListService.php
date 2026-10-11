<?php

declare(strict_types=1);

namespace App\Services;

use App\DataObjects\DueProduct;
use App\DataObjects\ShoppingList;
use App\Models\Product;
use App\Models\ProductSnooze;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns purchase history into "what am I about to run out of?".
 *
 * No date arithmetic happens in SQL. Production is MariaDB and the tests are
 * sqlite in memory, and the two share no date functions — DATEDIFF and
 * julianday each break the other engine. Everything the database does here
 * (COUNT DISTINCT, GROUP BY, HAVING, ORDER BY) behaves identically on both, and
 * the interval maths is done in PHP with Carbon.
 */
final class ShoppingListService
{
    public function due(?CarbonImmutable $today = null): ShoppingList
    {
        $today = ($today ?? CarbonImmutable::now())->startOfDay();

        $candidates = $this->candidates();

        if ($candidates === []) {
            return new ShoppingList([], [], 0, 0);
        }

        $dates = $this->purchaseDates($candidates);
        $snoozes = $this->snoozes($candidates);

        $overdue = [];
        $dueSoon = [];
        $snoozedCount = 0;

        $dueSoonFactor = (float) config('shoplist.due_soon_factor');
        $maxInterval = (int) config('shoplist.max_interval_days');
        $staleFactor = (float) config('shoplist.stale_factor');
        $staleFloor = (int) config('shoplist.stale_floor_days');

        foreach ($dates as $productId => $productDates) {
            $interval = $this->interval($productDates);

            if ($interval === null || $interval > $maxInterval) {
                // Not predictable, or seasonal rather than routine.
                continue;
            }

            $lastPurchase = $productDates[count($productDates) - 1];
            $daysSince = (int) $lastPurchase->diffInDays($today);

            if ($daysSince < 0) {
                // A receipt dated in the future. Nothing sensible to say.
                continue;
            }

            if ($daysSince > max($staleFactor * $interval, (float) $staleFloor)) {
                // Not forgotten — dropped. Reminding about it forever is noise.
                continue;
            }

            $ratio = $daysSince / $interval;

            if ($ratio < $dueSoonFactor) {
                continue;
            }

            // A snooze hides the item for one more of its own cycles, measured
            // from the tap and against the interval as it stands right now.
            $snoozedAt = $snoozes[$productId] ?? null;

            if ($snoozedAt !== null && $today->lessThan($snoozedAt->addDays($interval))) {
                $snoozedCount++;

                continue;
            }

            $item = new DueProduct(
                productId: $productId,
                name: '',
                intervalDays: $interval,
                daysSince: $daysSince,
                lastPurchase: $lastPurchase,
                dueDate: $lastPurchase->addDays($interval),
                ratio: $ratio,
            );

            if ($item->isOverdue()) {
                $overdue[] = $item;
            } else {
                $dueSoon[] = $item;
            }
        }

        [$overdue, $dueSoon] = $this->withNames($overdue, $dueSoon);

        return new ShoppingList(
            overdue: $overdue,
            dueSoon: $dueSoon,
            candidateCount: count($candidates),
            snoozedCount: $snoozedCount,
        );
    }

    /**
     * Hide a product for roughly one more of its own cycles.
     *
     * Returns the product as it looked when it was dismissed, so the caller can
     * say what it just did without asking again. Null means the id is not a
     * product with enough history to be on the list in the first place.
     */
    public function snooze(int $productId, ?CarbonImmutable $today = null): ?DueProduct
    {
        $today = ($today ?? CarbonImmutable::now())->startOfDay();

        $dates = $this->purchaseDates([$productId]);

        if (! isset($dates[$productId])) {
            return null;
        }

        $productDates = $dates[$productId];
        $interval = $this->interval($productDates);

        if ($interval === null) {
            return null;
        }

        ProductSnooze::updateOrCreate(
            ['product_id' => $productId],
            ['snoozed_at' => $today->toDateString()],
        );

        $lastPurchase = $productDates[count($productDates) - 1];
        $daysSince = max(0, (int) $lastPurchase->diffInDays($today));

        [$overdue] = $this->withNames([
            new DueProduct(
                productId: $productId,
                name: '',
                intervalDays: $interval,
                daysSince: $daysSince,
                lastPurchase: $lastPurchase,
                dueDate: $lastPurchase->addDays($interval),
                ratio: $daysSince / $interval,
            ),
        ], []);

        return $overdue[0];
    }

    /**
     * Products bought on enough separate days to have a habit.
     *
     * COUNT(DISTINCT date), not COUNT(*): the same product on two lines of one
     * receipt, or from two shops on one afternoon, is one shopping trip.
     *
     * @return list<int>
     */
    private function candidates(): array
    {
        return $this->purchases()
            ->select('product_id')
            ->groupBy('product_id')
            ->havingRaw('COUNT(DISTINCT date) >= ?', [(int) config('shoplist.min_purchases')])
            ->pluck('product_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Rows that are actually a purchase.
     *
     * A basket-wide discount is a line in `histories` like any other — the
     * parser has to return "Lidl Plus -saastosi" as its own line or the line
     * totals stop adding up to the receipt. It is not something anybody buys,
     * though, so it must never be predicted: before this filter it was the
     * most regular "product" in the shop, due every five days.
     *
     * The sign of the total is the discriminator, not `is_discount` — that
     * flag is also set on a real product with a discount folded into it, and
     * those are ordinary purchases that have to stay.
     */
    private function purchases(): Builder
    {
        return DB::table('histories')->where('total', '>', 0);
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, list<CarbonImmutable>> product id => ascending distinct dates
     */
    private function purchaseDates(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = $this->purchases()
            ->select('product_id', 'date')
            ->whereIn('product_id', $productIds)
            ->whereNotNull('date')
            ->groupBy('product_id', 'date')
            ->orderBy('product_id')
            ->orderBy('date')
            ->get();

        $dates = [];

        foreach ($rows as $row) {
            // histories.date has no cast on the model, so it arrives as a
            // string. A row we cannot read is skipped rather than allowed to
            // take the whole command down.
            $date = $this->parseDate((string) $row->date);

            if ($date === null) {
                continue;
            }

            $dates[(int) $row->product_id][] = $date;
        }

        return $dates;
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        } catch (Throwable) {
            return null;
        }

        if ($date === false || $date->year < 1971) {
            return null;
        }

        return $date;
    }

    /**
     * The median of the recent gaps, in whole days.
     *
     * The median rather than the mean because grocery data is full of outliers
     * — holidays, bulk buys, a month abroad — and a single one drags a mean
     * somewhere that describes no actual behaviour. Only the last few gaps
     * count, so a habit that changes is followed rather than averaged with its
     * own past.
     *
     * Null when there is not enough to go on.
     *
     * @param  list<CarbonImmutable>  $dates  ascending, distinct
     */
    private function interval(array $dates): ?int
    {
        if (count($dates) < (int) config('shoplist.min_purchases')) {
            return null;
        }

        $gaps = [];

        for ($i = 1, $n = count($dates); $i < $n; $i++) {
            // Carbon 3 returns a signed float here, so the order matters.
            $gaps[] = (int) $dates[$i - 1]->diffInDays($dates[$i]);
        }

        $gaps = array_values(array_filter($gaps, static fn (int $gap): bool => $gap > 0));

        if ($gaps === []) {
            return null;
        }

        $window = max(1, (int) config('shoplist.gap_window'));
        $gaps = array_slice($gaps, -$window);

        sort($gaps);
        $middle = intdiv(count($gaps), 2);

        $median = count($gaps) % 2 === 1
            ? (float) $gaps[$middle]
            : ($gaps[$middle - 1] + $gaps[$middle]) / 2;

        return max(1, (int) round($median));
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, CarbonImmutable>
     */
    private function snoozes(array $productIds): array
    {
        return ProductSnooze::query()
            ->whereIn('product_id', $productIds)
            ->get()
            ->mapWithKeys(static fn (ProductSnooze $snooze): array => [
                (int) $snooze->product_id => $snooze->snoozed_at->startOfDay(),
            ])
            ->all();
    }

    /**
     * Names are looked up last, for the handful of products that made the list
     * rather than every product with a history.
     *
     * @param  list<DueProduct>  $overdue
     * @param  list<DueProduct>  $dueSoon
     * @return array{0: list<DueProduct>, 1: list<DueProduct>}
     */
    private function withNames(array $overdue, array $dueSoon): array
    {
        $ids = array_map(static fn (DueProduct $item): int => $item->productId, array_merge($overdue, $dueSoon));

        if ($ids === []) {
            return [[], []];
        }

        $names = Product::query()->whereIn('id', $ids)->pluck('name', 'id');

        $name = static fn (DueProduct $item): DueProduct => new DueProduct(
            productId: $item->productId,
            name: (string) ($names[$item->productId] ?? ''),
            intervalDays: $item->intervalDays,
            daysSince: $item->daysSince,
            lastPurchase: $item->lastPurchase,
            dueDate: $item->dueDate,
            ratio: $item->ratio,
        );

        $overdue = $this->sorted(array_map($name, $overdue));
        $dueSoon = $this->sorted(array_map($name, $dueSoon));

        // The cap is on the list as a whole, not on each half of it. Overdue
        // items are the point of the feature, so they fill it first.
        $max = (int) config('shoplist.max_items');

        return [
            array_slice($overdue, 0, $max),
            array_slice($dueSoon, 0, max(0, $max - count($overdue))),
        ];
    }

    /**
     * Most overdue first. The name tie-break is not cosmetic: without it two
     * items with the same ratio come back in whatever order the rows arrived.
     *
     * @param  list<DueProduct>  $items
     * @return list<DueProduct>
     */
    private function sorted(array $items): array
    {
        usort($items, static function (DueProduct $a, DueProduct $b): int {
            return [$b->ratio, $a->name] <=> [$a->ratio, $b->name];
        });

        return $items;
    }
}
