<?php

declare(strict_types=1);

namespace Tests\Feature\CheckHistory;

use App\DataObjects\ReconciliationReport;
use App\Models\Category;
use App\Models\History;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Unit;
use App\Services\Wallet\ReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The matching rules, over records handed in directly. No HTTP anywhere here.
 */
class ReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReconciliationService $service;

    private Category $category;

    private Unit $unit;

    private int $line = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ReconciliationService;
        $this->category = Category::create(['name' => 'default']);
        $this->unit = Unit::create(['name' => 'kpl']);
    }

    public function test_a_payee_matches_a_wallet_payee_case_insensitively(): void
    {
        $shop = Shop::create(['name' => 'S-Market', 'wallet_payee' => 's-market']);
        $this->receipt($shop, '2026-09-09');

        $report = $this->reconcile([$this->record('S-MARKET', '2026-09-09')]);

        $this->assertCount(1, $report->matched);
        $this->assertSame($shop->id, $report->matched[0]->shopId);
    }

    public function test_a_payee_matches_a_shop_name_when_no_wallet_payee_is_set(): void
    {
        // The real Alanya and Beno case: the push sends `wallet_payee ?: name`,
        // so the name is what came back and the map has to cover it.
        $shop = Shop::create(['name' => 'Alanya']);
        $this->receipt($shop, '2026-09-07');

        $report = $this->reconcile([$this->record('alanya', '2026-09-07')]);

        $this->assertCount(1, $report->matched);
    }

    public function test_an_explicit_wallet_payee_beats_another_shops_name(): void
    {
        $named = Shop::create(['name' => 'Lidl']);
        $configured = Shop::create(['name' => 'Lidl Kaleva', 'wallet_payee' => 'Lidl']);

        $report = $this->reconcile([$this->record('Lidl', '2026-09-11')]);

        $this->assertSame($configured->id, $report->missing[0]->shopId);
        $this->assertNotSame($named->id, $report->missing[0]->shopId);
    }

    public function test_a_collision_goes_to_the_lowest_id_and_is_reported(): void
    {
        $first = Shop::create(['name' => 'Coop', 'wallet_payee' => 'Coop']);
        Shop::create(['name' => 'Coop Extra', 'wallet_payee' => 'Coop']);

        $report = $this->reconcile([$this->record('Coop', '2026-09-11')]);

        $this->assertSame($first->id, $report->missing[0]->shopId);
        $this->assertSame(['coop'], $report->ambiguousPayees);
    }

    public function test_an_unmatched_payee_is_reported_separately(): void
    {
        Shop::create(['name' => 'Lidl']);

        $report = $this->reconcile([$this->record('Rimi', '2026-09-11')]);

        $this->assertSame([], $report->missing);
        $this->assertCount(1, $report->unknownShop);
        $this->assertSame('Rimi', $report->unknownShop[0]->label());
    }

    public function test_a_blank_payee_is_reported_rather_than_dropped(): void
    {
        $report = $this->reconcile([$this->record('', '2026-09-11')]);

        $this->assertCount(1, $report->unknownShop);
        $this->assertSame('(no payee)', $report->unknownShop[0]->label());
    }

    public function test_unknown_payees_are_folded_per_payee(): void
    {
        $report = $this->reconcile([
            $this->record('Rimi', '2026-09-11', 10.00),
            $this->record('Rimi', '2026-09-10', 5.00),
            $this->record('Coop', '2026-09-09', 3.00),
        ]);

        $folded = $report->unknownByPayee();

        $this->assertSame('Rimi', $folded[0]['payee']);
        $this->assertSame(2, $folded[0]['count']);
        $this->assertSame(15.00, $folded[0]['total']);
        $this->assertSame('Coop', $folded[1]['payee']);
    }

    public function test_a_receipt_for_another_date_does_not_match(): void
    {
        $shop = Shop::create(['name' => 'Lidl']);
        $this->receipt($shop, '2026-09-10');

        $report = $this->reconcile([$this->record('Lidl', '2026-09-11')]);

        $this->assertCount(1, $report->missing);
    }

    public function test_a_receipt_from_another_shop_does_not_match(): void
    {
        $lidl = Shop::create(['name' => 'Lidl']);
        $other = Shop::create(['name' => 'Beno']);
        $this->receipt($other, '2026-09-11');

        $report = $this->reconcile([$this->record('Lidl', '2026-09-11')]);

        $this->assertCount(1, $report->missing);
        $this->assertSame($lidl->id, $report->missing[0]->shopId);
    }

    public function test_a_timestamp_valued_date_column_still_matches(): void
    {
        // histories.date is a timestamp holding midnight, and the model has no
        // cast, so it reads back "2026-09-09 00:00:00".
        $shop = Shop::create(['name' => 'Lidl']);
        $this->receipt($shop, '2026-09-09 00:00:00');

        $report = $this->reconcile([$this->record('Lidl', '2026-09-09')]);

        $this->assertCount(1, $report->matched);
    }

    public function test_two_expenses_on_one_day_with_one_receipt_leave_one_missing(): void
    {
        $shop = Shop::create(['name' => 'S-Market']);
        $this->receipt($shop, '2026-09-09');

        $report = $this->reconcile([
            $this->record('S-Market', '2026-09-09', 4.22),
            $this->record('S-Market', '2026-09-09', 5.18),
        ]);

        $this->assertCount(1, $report->matched);
        $this->assertCount(1, $report->missing);
    }

    public function test_two_expenses_with_two_receipts_that_day_are_both_matched(): void
    {
        // The real 9 Sep S-Market pair: two separate receipts were uploaded.
        $shop = Shop::create(['name' => 'S-Market']);
        $this->receipt($shop, '2026-09-09');
        $this->receipt($shop, '2026-09-09');

        $report = $this->reconcile([
            $this->record('S-Market', '2026-09-09', 4.22),
            $this->record('S-Market', '2026-09-09', 5.18),
        ]);

        $this->assertCount(2, $report->matched);
        $this->assertTrue($report->isClean());
    }

    public function test_many_line_items_of_one_receipt_count_as_one_receipt(): void
    {
        $shop = Shop::create(['name' => 'Lidl']);
        $this->receipt($shop, '2026-09-11', lines: 24);

        $report = $this->reconcile([
            $this->record('Lidl', '2026-09-11', 10.00),
            $this->record('Lidl', '2026-09-11', 20.00),
        ]);

        $this->assertCount(1, $report->matched);
        $this->assertCount(1, $report->missing);
    }

    public function test_amounts_are_reported_positive(): void
    {
        Shop::create(['name' => 'Lidl']);

        $report = $this->reconcile([$this->record('Lidl', '2026-09-11', 24.90)]);

        $this->assertSame(24.90, $report->missing[0]->amount);
        $this->assertSame(24.90, $report->missingTotal());
    }

    public function test_it_does_not_query_once_per_record(): void
    {
        $shop = Shop::create(['name' => 'Lidl']);
        $this->receipt($shop, '2026-09-11');

        $records = [];
        for ($i = 0; $i < 20; $i++) {
            $records[] = $this->record('Lidl', '2026-09-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT));
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->reconcile($records);

        $this->assertLessThanOrEqual(3, $queries);
    }

    public function test_a_record_with_no_date_is_ignored(): void
    {
        Shop::create(['name' => 'Lidl']);

        $report = $this->reconcile([['id' => 'x', 'counterParty' => 'Lidl', 'amount' => ['value' => -1.0]]]);

        $this->assertSame(0, $report->total());
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function reconcile(array $records): ReconciliationReport
    {
        return $this->service->reconcile($records, CarbonImmutable::parse('2026-09-01'), 30);
    }

    /**
     * @return array<string, mixed>
     */
    private function record(string $payee, string $date, float $amount = 10.0): array
    {
        return [
            'id' => 'rec-'.(++$this->line),
            'counterParty' => $payee,
            // Wallet stamps records at noon UTC and stores expenses negative.
            'recordDate' => $date.'T12:00:00.000Z',
            'amount' => ['value' => -$amount, 'currencyCode' => 'EUR'],
        ];
    }

    private function receipt(Shop $shop, string $date, int $lines = 1): void
    {
        $filename = 'receipt_'.(++$this->line).'.pdf';

        for ($i = 0; $i < $lines; $i++) {
            $product = Product::create([
                'name' => 'Item '.(++$this->line),
                'category_id' => $this->category->id,
                'unit_id' => $this->unit->id,
            ]);

            History::create([
                'product_id' => $product->id,
                'shop_id' => $shop->id,
                'amount' => 1,
                'price' => 1.5,
                'total' => 1.5,
                'date' => $date,
                'filename' => $filename,
            ]);
        }
    }
}
