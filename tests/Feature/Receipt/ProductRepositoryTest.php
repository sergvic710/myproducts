<?php

declare(strict_types=1);

namespace Tests\Feature\Receipt;

use App\DataObjects\ParsedItem;
use App\DataObjects\ParsedReceipt;
use App\Exceptions\ReceiptParseException;
use App\Models\Category;
use App\Models\History;
use App\Models\Product;
use App\Models\Shop;
use App\Repositories\ProductRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private ProductRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        Shop::create(['name' => 'Prisma']);
        Category::create(['name' => 'default']);
        Category::create(['name' => 'Fruit']);

        $this->repository = new ProductRepository;
    }

    public function test_it_writes_products_and_history_rows(): void
    {
        $saved = $this->repository->saveReceipt($this->receipt(), 'receipt.pdf');

        $this->assertSame(2, $saved);
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('histories', 2);

        $product = Product::where('name', 'BANAANI')->firstOrFail();
        $history = History::where('product_id', $product->id)->firstOrFail();

        // price is the price of ONE unit, total is the line total.
        // The old code read the wrong array key here and stored null.
        $this->assertEqualsWithDelta(1.89, (float) $history->price, 0.001);
        $this->assertEqualsWithDelta(0.857, (float) $history->amount, 0.001);
        $this->assertEqualsWithDelta(1.62, (float) $history->total, 0.001);
        $this->assertSame('Fruit', $product->category->name);
        $this->assertSame('kg', $product->unit->name);
    }

    public function test_saving_the_same_receipt_twice_does_not_duplicate_rows(): void
    {
        $this->repository->saveReceipt($this->receipt(), 'receipt.pdf');
        $this->repository->saveReceipt($this->receipt(), 'receipt.pdf');

        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('histories', 2);
    }

    public function test_an_unknown_category_falls_back_and_creates_no_new_row(): void
    {
        $receipt = new ParsedReceipt(
            shop: 'Prisma',
            date: CarbonImmutable::parse('2025-09-21'),
            total: 1.99,
            items: [new ParsedItem('MYSTERY ITEM', 'Space Food', 'kpl', 1, 1.99, 1.99)],
        );

        $this->repository->saveReceipt($receipt, 'receipt.pdf');

        $this->assertDatabaseCount('categories', 2);
        $this->assertSame('default', Product::where('name', 'MYSTERY ITEM')->firstOrFail()->category->name);
    }

    public function test_an_unknown_shop_is_rejected(): void
    {
        $receipt = new ParsedReceipt(
            shop: 'K-Market',
            date: CarbonImmutable::parse('2025-09-21'),
            total: 1.0,
            items: [new ParsedItem('KURKKU', 'default', 'kpl', 1, 1.0, 1.0)],
        );

        $this->expectException(ReceiptParseException::class);

        $this->repository->saveReceipt($receipt, 'receipt.pdf');

        $this->assertDatabaseCount('shops', 1);
    }

    public function test_the_shop_name_is_matched_without_case(): void
    {
        $receipt = new ParsedReceipt(
            shop: 'PRISMA',
            date: CarbonImmutable::parse('2025-09-21'),
            total: 1.0,
            items: [new ParsedItem('KURKKU', 'default', 'kpl', 1, 1.0, 1.0)],
        );

        $this->assertSame(1, $this->repository->saveReceipt($receipt, 'receipt.pdf'));
    }

    public function test_a_discount_folded_into_a_product_is_stored_on_the_history_row(): void
    {
        // "Italiansalaatti 1,79" with "Alennus 20% -0,36" folded in:
        // unit price stays the shelf price, the line total is what was paid.
        $receipt = new ParsedReceipt(
            shop: 'Prisma',
            date: CarbonImmutable::parse('2025-10-24'),
            total: 1.43,
            items: [new ParsedItem('Italiansalaatti', 'default', 'kpl', 1, 1.79, 1.43, true, 0.36)],
        );

        $this->repository->saveReceipt($receipt, 'lidl.png');

        $history = History::where('filename', 'lidl.png')->firstOrFail();

        $this->assertEqualsWithDelta(1.79, (float) $history->price, 0.001);
        $this->assertEqualsWithDelta(1.43, (float) $history->total, 0.001);
        $this->assertEqualsWithDelta(0.36, (float) $history->discount_price, 0.001);
        $this->assertSame(1, (int) $history->is_discount);
    }

    public function test_a_basket_wide_discount_stays_its_own_line(): void
    {
        $receipt = new ParsedReceipt(
            shop: 'Prisma',
            date: CarbonImmutable::parse('2025-10-24'),
            total: 0.79,
            items: [
                new ParsedItem('Kanamestari', 'default', 'kpl', 1, 1.79, 1.79),
                new ParsedItem('Lidl Plus -saastosi', 'default', 'kpl', 1, -1.00, -1.00, true, 1.00),
            ],
        );

        $this->repository->saveReceipt($receipt, 'lidl.png');

        // The line totals still add up to what was paid.
        $this->assertEqualsWithDelta(0.79, (float) History::where('filename', 'lidl.png')->sum('total'), 0.001);
    }

    private function receipt(): ParsedReceipt
    {
        return new ParsedReceipt(
            shop: 'Prisma',
            date: CarbonImmutable::parse('2025-09-21'),
            total: 3.56,
            items: [
                new ParsedItem('BANAANI', 'Fruit', 'kg', 0.857, 1.89, 1.62),
                new ParsedItem('KURKKU', 'Fruit', 'kg', 0.314, 2.99, 0.94),
            ],
        );
    }
}
