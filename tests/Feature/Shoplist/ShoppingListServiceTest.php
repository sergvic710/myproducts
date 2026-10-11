<?php

declare(strict_types=1);

namespace Tests\Feature\Shoplist;

use App\Models\Category;
use App\Models\History;
use App\Models\Product;
use App\Models\ProductSnooze;
use App\Models\Shop;
use App\Models\Unit;
use App\Services\ShoppingListService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The interval maths, which is the whole feature.
 *
 * Every test passes an explicit "today" rather than freezing the clock, so a
 * failure says what date it disagreed about.
 */
class ShoppingListServiceTest extends TestCase
{
    use RefreshDatabase;

    private ShoppingListService $service;

    private Shop $shop;

    private Category $category;

    private Unit $unit;

    private int $line = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ShoppingListService;
        $this->shop = Shop::create(['name' => 'Prisma']);
        $this->category = Category::create(['name' => 'default']);
        $this->unit = Unit::create(['name' => 'kpl']);
    }

    public function test_it_ignores_a_product_with_fewer_than_three_purchase_dates(): void
    {
        $this->bought('Milk', ['2026-09-01', '2026-09-08']);

        $list = $this->service->due($this->day('2026-09-20'));

        $this->assertSame(0, $list->candidateCount);
        $this->assertTrue($list->isEmpty());
    }

    public function test_it_ignores_a_product_bought_three_times_on_the_same_day(): void
    {
        // Three separate receipt lines, one shopping trip.
        $this->bought('Milk', ['2026-09-01', '2026-09-01', '2026-09-01']);

        $list = $this->service->due($this->day('2026-09-20'));

        $this->assertSame(0, $list->candidateCount);
    }

    public function test_duplicate_lines_on_one_day_count_as_one_purchase(): void
    {
        // Four rows, three distinct dates: the interval must read as 7, not 3.5.
        $this->bought('Milk', ['2026-09-01', '2026-09-01', '2026-09-08', '2026-09-15']);

        $list = $this->service->due($this->day('2026-09-25'));

        $this->assertCount(1, $list->overdue);
        $this->assertSame(7, $list->overdue[0]->intervalDays);
    }

    public function test_it_marks_a_product_overdue_past_its_interval(): void
    {
        $this->bought('Milk', ['2026-09-01', '2026-09-08', '2026-09-15']);

        $list = $this->service->due($this->day('2026-09-25'));

        $this->assertCount(1, $list->overdue);
        $this->assertSame('Milk', $list->overdue[0]->name);
        $this->assertSame(7, $list->overdue[0]->intervalDays);
        $this->assertSame(10, $list->overdue[0]->daysSince);
        $this->assertSame(3, $list->overdue[0]->daysOverdue());
    }

    public function test_it_marks_a_product_due_soon_at_exactly_the_threshold(): void
    {
        // Interval 8, six days elapsed: ratio is exactly 0.75, and the
        // threshold is inclusive.
        $this->bought('Coffee', ['2026-09-01', '2026-09-09', '2026-09-17']);

        $list = $this->service->due($this->day('2026-09-23'));

        $this->assertSame([], $list->overdue);
        $this->assertCount(1, $list->dueSoon);
        $this->assertSame(2, $list->dueSoon[0]->daysUntilDue());
    }

    public function test_it_hides_a_product_that_is_not_due_yet(): void
    {
        $this->bought('Coffee', ['2026-09-01', '2026-09-09', '2026-09-17']);

        $list = $this->service->due($this->day('2026-09-21'));

        $this->assertTrue($list->isEmpty());
        $this->assertSame(1, $list->candidateCount);
    }

    public function test_it_uses_the_median_gap_not_the_mean(): void
    {
        // Gaps of 7, 7, 7, 7 and 60. The mean is 17.6 and describes nothing;
        // the median is 7 and describes the habit.
        $this->bought('Milk', [
            '2026-01-01', '2026-01-08', '2026-01-15', '2026-01-22', '2026-01-29', '2026-03-30',
        ]);

        $list = $this->service->due($this->day('2026-04-06'));

        $this->assertCount(1, $list->overdue);
        $this->assertSame(7, $list->overdue[0]->intervalDays);
    }

    public function test_it_only_looks_at_the_recent_gaps(): void
    {
        // Monthly for most of a year, then weekly. The prediction should follow
        // the change rather than average it with its own past.
        $dates = [];
        $date = CarbonImmutable::parse('2026-01-01');

        for ($i = 0; $i < 8; $i++) {
            $dates[] = $date->toDateString();
            $date = $date->addDays(30);
        }

        for ($i = 0; $i < 6; $i++) {
            $dates[] = $date->toDateString();
            $date = $date->addDays(7);
        }

        $this->bought('Bread', $dates);

        $list = $this->service->due($this->day($date->addDays(3)->toDateString()));

        $this->assertCount(1, $list->overdue);
        $this->assertSame(7, $list->overdue[0]->intervalDays);
    }

    public function test_it_drops_a_product_that_is_no_longer_bought(): void
    {
        // Weekly, then silence for the best part of a year. It was dropped, not
        // forgotten — without this rule it sits at the top of the list forever.
        $this->bought('Cider', ['2025-01-01', '2025-01-08', '2025-01-15']);

        $list = $this->service->due($this->day('2026-09-13'));

        $this->assertTrue($list->isEmpty());
        $this->assertSame(1, $list->candidateCount);
    }

    public function test_it_drops_a_seasonal_product(): void
    {
        // Strawberries every June. The staleness rule cannot catch this, since
        // four times a year is a long time; the interval ceiling can.
        $this->bought('Strawberries', ['2023-06-01', '2024-06-01', '2025-06-01']);

        $list = $this->service->due($this->day('2026-06-05'));

        $this->assertTrue($list->isEmpty());
    }

    public function test_it_sorts_overdue_first_then_by_how_overdue(): void
    {
        $milk = $this->bought('Milk', ['2026-09-01', '2026-09-04', '2026-09-07']);      // every 3, 13 days on
        $bread = $this->bought('Bread', ['2026-08-25', '2026-09-01', '2026-09-08']);    // every 7, 12 days on
        $coffee = $this->bought('Coffee', ['2026-07-27', '2026-08-16', '2026-09-05']);  // every 20, due soon

        $list = $this->service->due($this->day('2026-09-20'));

        $this->assertSame(
            [$milk->id, $bread->id, $coffee->id],
            array_map(static fn ($item) => $item->productId, $list->all()),
        );
    }

    public function test_it_breaks_a_tie_on_the_product_name(): void
    {
        $b = $this->bought('Bbb', ['2026-09-01', '2026-09-08', '2026-09-15']);
        $a = $this->bought('Aaa', ['2026-09-01', '2026-09-08', '2026-09-15']);

        $list = $this->service->due($this->day('2026-09-25'));

        $this->assertSame(
            [$a->id, $b->id],
            array_map(static fn ($item) => $item->productId, $list->all()),
        );
    }

    public function test_it_caps_the_list_as_a_whole(): void
    {
        config(['shoplist.max_items' => 2]);

        $this->bought('Milk', ['2026-09-01', '2026-09-04', '2026-09-07']);
        $this->bought('Bread', ['2026-09-01', '2026-09-08', '2026-09-15']);
        $this->bought('Rice', ['2026-09-01', '2026-09-06', '2026-09-11']);
        $this->bought('Coffee', ['2026-08-01', '2026-08-21', '2026-09-10']);

        $list = $this->service->due($this->day('2026-09-20'));

        $this->assertSame(2, $list->count());
        $this->assertSame([], $list->dueSoon);
    }

    public function test_a_snoozed_product_is_hidden_for_one_more_cycle(): void
    {
        $milk = $this->bought('Milk', ['2026-09-01', '2026-09-08', '2026-09-15']);

        $this->service->snooze($milk->id, $this->day('2026-09-25'));

        $list = $this->service->due($this->day('2026-10-01'));

        $this->assertTrue($list->isEmpty());
        $this->assertSame(1, $list->snoozedCount);
    }

    public function test_a_snooze_expires_after_one_interval(): void
    {
        $milk = $this->bought('Milk', ['2026-09-01', '2026-09-08', '2026-09-15']);

        $this->service->snooze($milk->id, $this->day('2026-09-25'));

        $list = $this->service->due($this->day('2026-10-02'));

        $this->assertCount(1, $list->overdue);
        $this->assertSame(0, $list->snoozedCount);
    }

    public function test_a_shortened_interval_ends_the_snooze_early(): void
    {
        // This is what a stored snoozed_until would get wrong: the window is
        // derived from the interval as it stands now, not as it was at the tap.
        //
        // Both halves ask about the same day. Only the interval changes, and
        // the fill-in purchases are all older than the last one, so the due
        // date moves for that reason alone.
        $milk = $this->bought('Milk', ['2026-09-01', '2026-09-11', '2026-09-21']);

        $this->service->snooze($milk->id, $this->day('2026-09-21'));

        // Every ten days: due on 1 October, snoozed until 1 October.
        $this->assertTrue($this->service->due($this->day('2026-09-30'))->isEmpty());

        // It turns out to be every five. Due on 26 September, and the snooze
        // ended on the 26th with it.
        $this->buy($milk, '2026-09-06');
        $this->buy($milk, '2026-09-16');

        $this->assertCount(1, $this->service->due($this->day('2026-09-30'))->overdue);
    }

    public function test_a_purchase_after_the_tap_never_leaves_the_item_suppressed(): void
    {
        $milk = $this->bought('Milk', ['2026-09-01', '2026-09-08', '2026-09-15']);

        $this->service->snooze($milk->id, $this->day('2026-09-22'));
        $this->buy($milk, '2026-09-23');

        // Not due yet, because it was just bought.
        $this->assertTrue($this->service->due($this->day('2026-09-26'))->isEmpty());

        // Due again a cycle after that purchase, with the snooze long expired.
        $this->assertCount(1, $this->service->due($this->day('2026-09-30'))->overdue);
    }

    public function test_snoozing_twice_updates_the_same_row(): void
    {
        $milk = $this->bought('Milk', ['2026-09-01', '2026-09-08', '2026-09-15']);

        $this->service->snooze($milk->id, $this->day('2026-09-22'));
        $this->service->snooze($milk->id, $this->day('2026-09-24'));

        $this->assertDatabaseCount('product_snoozes', 1);

        $snooze = ProductSnooze::firstWhere('product_id', $milk->id);

        $this->assertNotNull($snooze);
        $this->assertSame('2026-09-24', $snooze->snoozed_at->toDateString());
    }

    public function test_snoozing_never_writes_a_purchase(): void
    {
        $milk = $this->bought('Milk', ['2026-09-01', '2026-09-08', '2026-09-15']);

        $before = History::count();
        $this->service->snooze($milk->id, $this->day('2026-09-22'));

        $this->assertSame($before, History::count());
    }

    public function test_snoozing_an_unknown_product_returns_null(): void
    {
        $this->assertNull($this->service->snooze(9999, $this->day('2026-09-22')));
        $this->assertDatabaseCount('product_snoozes', 0);
    }

    public function test_an_orphaned_snooze_goes_with_its_product(): void
    {
        $milk = $this->bought('Milk', ['2026-09-01', '2026-09-08', '2026-09-15']);
        $this->service->snooze($milk->id, $this->day('2026-09-22'));

        $milk->delete();

        $this->assertDatabaseCount('product_snoozes', 0);
    }

    /**
     * @param  list<string>  $dates
     */
    private function bought(string $name, array $dates): Product
    {
        $product = Product::create([
            'name' => $name,
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
        ]);

        foreach ($dates as $date) {
            $this->buy($product, $date);
        }

        return $product;
    }

    private function buy(Product $product, string $date): void
    {
        History::create([
            'product_id' => $product->id,
            'shop_id' => $this->shop->id,
            'amount' => 1,
            'price' => 1.5,
            'total' => 1.5,
            'date' => $date,
            // Distinct, or histories_receipt_line_unique collapses the rows.
            'filename' => 'receipt_'.(++$this->line).'.pdf',
        ]);
    }

    private function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date)->startOfDay();
    }
}
