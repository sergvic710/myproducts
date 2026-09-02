<?php

declare(strict_types=1);

namespace Tests\Feature\Receipt;

use App\Jobs\ProcessReceipt;
use App\Models\Category;
use App\Models\History;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Prism\Prism\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Tests\TestCase;

class ProcessReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Shop::create(['name' => 'Prisma']);
        Category::create(['name' => 'default']);
    }

    public function test_it_reads_a_file_and_writes_it_to_the_database(): void
    {
        Prism::fake([$this->answer()]);

        (new ProcessReceipt($this->fixture(), 'receipt.pdf'))
            ->handle(app(\App\Services\Parser\AiReceiptParser::class), app(\App\Repositories\ProductRepository::class));

        $this->assertDatabaseHas('histories', ['filename' => 'receipt.pdf']);
        $this->assertSame(1, Product::count());
    }

    public function test_it_skips_a_receipt_that_is_already_saved(): void
    {
        $fake = Prism::fake([$this->answer()]);

        $unit = \App\Models\Unit::create(['name' => 'kpl']);
        $category = Category::where('name', 'default')->firstOrFail();
        $shop = Shop::where('name', 'Prisma')->firstOrFail();

        $product = Product::create([
            'name' => 'OLD',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'count' => 1,
        ]);
        History::create([
            'product_id' => $product->id,
            'shop_id' => $shop->id,
            'price' => 1,
            'amount' => 1,
            'total' => 1,
            'date' => '2025-09-21',
            'filename' => 'receipt.pdf',
        ]);

        (new ProcessReceipt($this->fixture(), 'receipt.pdf'))
            ->handle(app(\App\Services\Parser\AiReceiptParser::class), app(\App\Repositories\ProductRepository::class));

        // No API call and no extra rows.
        $fake->assertCallCount(0);
        $this->assertSame(1, History::count());
    }

    private function answer(): StructuredResponseFake
    {
        return StructuredResponseFake::make()->withStructured([
            'shop' => 'Prisma',
            'date' => '2025-09-21',
            'total' => 1.62,
            'products' => [
                [
                    'name' => 'BANAANI',
                    'category' => 'default',
                    'unit' => 'kg',
                    'amount' => 0.857,
                    'unit_price' => 1.89,
                    'price' => 1.62,
                    'is_discount' => false,
                    'discount_price' => 0,
                ],
            ],
        ]);
    }

    private function fixture(): string
    {
        return base_path('tests/Fixtures/prisma_receipt.pdf');
    }
}
