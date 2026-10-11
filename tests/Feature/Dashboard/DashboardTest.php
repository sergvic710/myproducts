<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\Category;
use App\Models\History;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Unit;
use App\Models\User;
use App\Models\WalletRecord;
use App\Services\DashboardService;
use App\Services\MetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The dashboard's arithmetic, which is the only part of it that can be wrong
 * quietly. Every test passes an explicit month rather than freezing the clock.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private DashboardService $service;

    private Shop $shop;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DashboardService;
        $this->shop = Shop::create(['name' => 'Prisma']);
        $this->unit = Unit::create(['name' => 'kpl']);
    }

    public function test_it_sums_the_month_and_counts_receipts_not_rows(): void
    {
        // Two receipts, five lines. A receipt is a distinct filename.
        $this->line('Milk', '2026-09-03', 2.50, 'a.pdf');
        $this->line('Bread', '2026-09-03', 1.20, 'a.pdf');
        $this->line('Eggs', '2026-09-03', 3.30, 'a.pdf');
        $this->line('Milk', '2026-09-17', 2.50, 'b.pdf');
        $this->line('Coffee', '2026-09-17', 6.90, 'b.pdf');

        $spending = $this->service->spending($this->month('2026-09'));

        $this->assertSame(16.40, round($spending['total'], 2));
        $this->assertSame(2, $spending['receipts']);
        $this->assertSame(5, $spending['items']);
        $this->assertSame(8.20, round($spending['averageReceipt'], 2));
    }

    public function test_it_ignores_receipts_outside_the_month(): void
    {
        $this->line('Milk', '2026-08-31', 10.00, 'august.pdf');
        $this->line('Milk', '2026-09-01', 1.00, 'september.pdf');
        $this->line('Milk', '2026-10-01', 10.00, 'october.pdf');

        $spending = $this->service->spending($this->month('2026-09'));

        $this->assertSame(1.00, round($spending['total'], 2));
        $this->assertSame(1, $spending['receipts']);
    }

    public function test_it_compares_with_the_previous_month(): void
    {
        $this->line('Milk', '2026-08-10', 100.00, 'august.pdf');
        $this->line('Milk', '2026-09-10', 150.00, 'september.pdf');

        $spending = $this->service->spending($this->month('2026-09'));

        $this->assertSame(100.00, round($spending['previousTotal'], 2));
        $this->assertSame(50.0, round($spending['change'], 1));
    }

    public function test_it_reports_no_change_when_the_previous_month_is_empty(): void
    {
        // "+100%" against a month with no receipts in it would be invented.
        $this->line('Milk', '2026-09-10', 150.00, 'september.pdf');

        $this->assertNull($this->service->spending($this->month('2026-09'))['change']);
    }

    public function test_it_sums_total_rather_than_price_times_amount(): void
    {
        // A weighed line: 0.78 kg at 0.74 EUR/kg charged 0.58.
        History::create([
            'product_id' => $this->product('Tomatoes')->id,
            'shop_id' => $this->shop->id,
            'price' => 0.74,
            'amount' => 0.78,
            'total' => 0.58,
            'date' => '2026-09-16',
            'filename' => 'lidl.jpg',
        ]);

        $this->assertSame(0.58, round($this->service->spending($this->month('2026-09'))['total'], 2));
    }

    public function test_it_groups_recent_receipts_by_filename(): void
    {
        $this->line('Milk', '2026-09-03', 2.50, 'a.pdf');
        $this->line('Bread', '2026-09-03', 1.50, 'a.pdf');
        $this->line('Coffee', '2026-09-17', 6.90, 'b.pdf');

        $receipts = $this->service->recentReceipts();

        $this->assertCount(2, $receipts);

        $byName = collect($receipts)->keyBy('filename');
        $this->assertSame(2, $byName['a.pdf']['items']);
        $this->assertSame(4.00, round($byName['a.pdf']['total'], 2));
        $this->assertSame('2026-09-03', $byName['a.pdf']['date']);
        $this->assertSame('Prisma', $byName['a.pdf']['shop']);
    }

    public function test_the_pipeline_is_ok_with_an_empty_queue(): void
    {
        $this->line('Milk', '2026-09-03', 2.50, 'a.pdf');

        $pipeline = $this->service->pipeline();

        $this->assertSame('ok', $pipeline['status']);
        $this->assertSame(0, $pipeline['pendingJobs']);
        $this->assertSame('a.pdf', $pipeline['lastReceipt']['filename']);
    }

    public function test_it_calls_out_a_queue_nobody_is_draining(): void
    {
        // This is the failure the block exists for: the job itself looks
        // perfectly healthy, only its age gives away that no worker is running.
        $this->queueJob(CarbonImmutable::parse('2026-09-20 12:00:00'));

        $pipeline = $this->service->pipeline(CarbonImmutable::parse('2026-09-20 12:40:00'));

        $this->assertTrue($pipeline['queueStalled']);
        $this->assertSame(40, $pipeline['oldestJobMinutes']);
        $this->assertSame('error', $pipeline['status']);
    }

    public function test_a_job_queued_a_moment_ago_is_not_stalled(): void
    {
        $this->queueJob(CarbonImmutable::parse('2026-09-20 12:00:00'));

        $pipeline = $this->service->pipeline(CarbonImmutable::parse('2026-09-20 12:01:00'));

        $this->assertFalse($pipeline['queueStalled']);
        $this->assertSame('warn', $pipeline['status']);
    }

    public function test_wallet_is_not_reported_while_it_is_switched_off(): void
    {
        config(['budgetbakers.enabled' => false]);
        $this->line('Milk', '2026-09-03', 2.50, 'a.pdf');

        $pipeline = $this->service->pipeline();

        $this->assertFalse($pipeline['walletEnabled']);
        $this->assertSame(0, $pipeline['walletPending']);
        $this->assertSame('ok', $pipeline['status']);
    }

    public function test_it_counts_receipts_that_never_reached_wallet(): void
    {
        config(['budgetbakers.enabled' => true, 'budgetbakers.token' => 'test-token']);

        $this->line('Milk', '2026-09-03', 2.50, 'pushed.pdf');
        $this->line('Milk', '2026-09-04', 2.50, 'skipped.pdf');
        $this->line('Milk', '2026-09-05', 2.50, 'never.pdf');

        WalletRecord::create(['filename' => 'pushed.pdf', 'status' => WalletRecord::CREATED]);
        // A shop with no Wallet template is settled, not pending.
        WalletRecord::create(['filename' => 'skipped.pdf', 'status' => WalletRecord::SKIPPED]);

        $pipeline = $this->service->pipeline();

        $this->assertSame(1, $pipeline['walletPending']);
        $this->assertSame('warn', $pipeline['status']);
    }

    public function test_metrics_accepts_a_date_instead_of_erroring(): void
    {
        // getTotalSumByCategory() used to assign its range only in the
        // `if (!$date)` branch, so passing one threw.
        $this->line('Milk', '2026-09-03', 2.50, 'a.pdf', 'Dairy');
        $this->line('Bread', '2026-09-04', 1.50, 'b.pdf', 'Bakery');
        $this->line('Cheese', '2026-09-05', 4.00, 'c.pdf', 'Dairy');
        $this->line('Beer', '2026-10-05', 9.00, 'd.pdf', 'Drinks');

        $byCategory = MetricsService::getTotalSumByCategory(Carbon::parse('2026-09-15'));

        $this->assertSame(['Dairy' => 6.5, 'Bakery' => 1.5], $byCategory);
        // Largest first, so the bars come out sorted.
        $this->assertSame('Dairy', array_key_first($byCategory));
    }

    public function test_the_dashboard_page_renders(): void
    {
        $this->line('Milk', '2026-09-03', 2.50, 'a.pdf', 'Dairy');

        $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Spending by category');
        $response->assertSee('Time to buy');
        $response->assertSee('Latest receipts');
    }

    private function line(string $product, string $date, float $total, string $filename, string $category = 'default'): void
    {
        History::create([
            'product_id' => $this->product($product, $category)->id,
            'shop_id' => $this->shop->id,
            'price' => $total,
            'amount' => 1,
            'total' => $total,
            'date' => $date,
            'filename' => $filename,
        ]);
    }

    private function product(string $name, string $category = 'default'): Product
    {
        return Product::firstOrCreate(
            ['name' => $name],
            [
                'category_id' => Category::firstOrCreate(['name' => $category])->id,
                'unit_id' => $this->unit->id,
                'count' => 1,
            ],
        );
    }

    private function queueJob(CarbonImmutable $availableAt): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $availableAt->getTimestamp(),
            'created_at' => $availableAt->getTimestamp(),
        ]);
    }

    private function month(string $month): CarbonImmutable
    {
        return CarbonImmutable::parse($month.'-15');
    }
}
