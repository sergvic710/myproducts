<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\DataObjects\ReconciliationReport;
use App\DataObjects\WalletExpense;
use App\Exceptions\WalletException;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Compares what Wallet says was spent on groceries with the receipts saved here.
 *
 * Split in two on purpose: check() does the fetching, reconcile() holds every
 * rule and takes records already in hand, so the matching can be tested without
 * a single HTTP call.
 */
final class ReconciliationService
{
    public function check(int $days, ?CarbonImmutable $today = null): ReconciliationReport
    {
        // Pinned to UTC rather than the app timezone: Wallet stamps records at
        // noon UTC, so a local midnight boundary could drag in the previous
        // day the moment APP_TIMEZONE stops being UTC.
        $since = ($today ?? CarbonImmutable::now('UTC'))->utc()->subDays($days)->startOfDay();

        $client = app(BudgetBakersClient::class);
        $category = (string) config('checkhistory.category');
        $categoryId = $client->findCategoryId($category);

        // Worth failing loudly. A missing category and the bare-uuid mistake
        // look identical from here — zero records, no error — and reporting
        // "nothing to do" when the truth is "I looked in the wrong place" is
        // the most misleading thing this could say.
        if ($categoryId === null) {
            throw WalletException::unknownCategory($category);
        }

        return $this->reconcile($client->expenseRecords($categoryId, $since), $since, $days);
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    public function reconcile(array $records, CarbonImmutable $since, int $days): ReconciliationReport
    {
        $ambiguous = [];
        $shops = $this->shopsByPayee($ambiguous);

        $expenses = [];

        foreach ($records as $record) {
            $expense = WalletExpense::fromArray($record);

            if ($expense->date === '') {
                continue;
            }

            $shop = $expense->payee !== ''
                ? ($shops[mb_strtolower($expense->payee)] ?? null)
                : null;

            $expenses[] = $expense->withShop($shop?->id, $shop?->name);
        }

        // Newest first, and by amount within a day. The order only matters
        // because of the line below it: when a day has more expenses than
        // receipts, which one gets called missing is arbitrary, and a fixed
        // order is what stops the answer flickering between runs.
        usort($expenses, static fn (WalletExpense $a, WalletExpense $b): int => [$b->date, $b->amount] <=> [$a->date, $a->amount]);

        $shopIds = array_values(array_unique(array_filter(
            array_map(static fn (WalletExpense $e): ?int => $e->shopId, $expenses),
        )));

        $counts = $this->receiptCounts($shopIds, $since);

        $missing = [];
        $unknownShop = [];
        $matched = [];

        foreach ($expenses as $expense) {
            if (! $expense->hasShop()) {
                $unknownShop[] = $expense;

                continue;
            }

            $key = $expense->shopId.'|'.$expense->date;

            // Spend the day's receipts one at a time. Two trips to the same
            // shop on one day with a single receipt saved is one receipt
            // missing, which a plain "does any receipt exist" check cannot see.
            if (($counts[$key] ?? 0) > 0) {
                $counts[$key]--;
                $matched[] = $expense;

                continue;
            }

            $missing[] = $expense;
        }

        return new ReconciliationReport(
            missing: $missing,
            unknownShop: $unknownShop,
            matched: $matched,
            ambiguousPayees: array_keys($ambiguous),
            since: $since,
            days: $days,
        );
    }

    /**
     * Lowercased payee or shop name => shop.
     *
     * PushReceiptToWallet sends `wallet_payee ?: name`, so a payee coming back
     * may be either, and the reverse map has to cover both columns.
     *
     * Two passes rather than one: a wallet_payee was typed on purpose and must
     * beat another shop's incidental name. Interleaved, a shop merely *called*
     * "Lidl" could take the key from the shop configured with wallet_payee
     * "Lidl". Neither column is unique, so a real collision is possible — the
     * lowest shop id wins it and the key is reported rather than swallowed.
     *
     * @param  array<string, bool>  $ambiguous
     * @return array<string, Shop>
     */
    private function shopsByPayee(array &$ambiguous): array
    {
        $shops = Shop::query()->orderBy('id')->get(['id', 'name', 'wallet_payee']);
        $map = [];

        foreach ([true, false] as $payeePass) {
            foreach ($shops as $shop) {
                $key = mb_strtolower(trim((string) ($payeePass ? $shop->wallet_payee : $shop->name)));

                if ($key === '') {
                    continue;
                }

                if (isset($map[$key])) {
                    if ($map[$key]->id !== $shop->id) {
                        $ambiguous[$key] = true;
                    }

                    continue;
                }

                $map[$key] = $shop;
            }
        }

        return $map;
    }

    /**
     * How many distinct receipts exist per shop and day.
     *
     * A receipt is a filename, not a row: histories holds one line per item, so
     * a 24-line shop is still one receipt.
     *
     * The grouping happens in PHP. histories.date is a timestamp column holding
     * midnight — the migration says ->date() and the schema disagrees — and the
     * model has no cast, so it reads back "2026-09-09 00:00:00". Laravel's
     * grammars smooth the MariaDB/sqlite split over whereDate(); they do not
     * over GROUP BY DATE(...), which is MySQL-only.
     *
     * @param  list<int>  $shopIds
     * @return array<string, int>
     */
    private function receiptCounts(array $shopIds, CarbonImmutable $since): array
    {
        if ($shopIds === []) {
            return [];
        }

        $rows = DB::table('histories')
            ->select('shop_id', 'date', 'filename')
            ->whereIn('shop_id', $shopIds)
            ->whereDate('date', '>=', $since->toDateString())
            ->get();

        $seen = [];

        foreach ($rows as $row) {
            $key = $row->shop_id.'|'.substr((string) $row->date, 0, 10);
            $seen[$key][(string) ($row->filename ?? '')] = true;
        }

        return array_map(static fn (array $filenames): int => count($filenames), $seen);
    }
}
