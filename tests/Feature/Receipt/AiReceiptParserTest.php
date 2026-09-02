<?php

declare(strict_types=1);

namespace Tests\Feature\Receipt;

use App\Exceptions\ReceiptParseException;
use App\Models\Category;
use App\Models\Shop;
use App\Services\Parser\AiReceiptParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Prism\Prism\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Tests\TestCase;

class AiReceiptParserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Shop::create(['name' => 'Prisma']);
        Shop::create(['name' => 'Lidl']);
        Category::create(['name' => 'default']);
        Category::create(['name' => 'Fruit']);
    }

    public function test_it_maps_a_model_answer_into_a_parsed_receipt(): void
    {
        Prism::fake([
            StructuredResponseFake::make()->withStructured([
                'shop' => 'Prisma',
                'date' => '2025-09-21',
                'total' => 25.32,
                'products' => [
                    [
                        'name' => 'BANAANI',
                        'category' => 'Fruit',
                        'unit' => 'kg',
                        'amount' => 0.857,
                        'unit_price' => 1.89,
                        'price' => 1.62,
                        'is_discount' => false,
                        'discount_price' => 0,
                    ],
                ],
            ]),
        ]);

        $receipt = app(AiReceiptParser::class)->parse($this->fixture());

        $this->assertSame('Prisma', $receipt->shop);
        $this->assertSame('2025-09-21', $receipt->date->toDateString());
        $this->assertSame(25.32, $receipt->total);
        $this->assertCount(1, $receipt->items);

        $item = $receipt->items[0];
        $this->assertSame('BANAANI', $item->name);
        $this->assertSame('kg', $item->unit);
        $this->assertSame(0.857, $item->amount);
        $this->assertSame(1.89, $item->unitPrice);
        $this->assertSame(1.62, $item->price);
    }

    public function test_it_derives_the_unit_price_when_the_model_leaves_it_out(): void
    {
        Prism::fake([
            StructuredResponseFake::make()->withStructured([
                'shop' => 'Prisma',
                'date' => '2025-09-21',
                'total' => 1.58,
                'products' => [
                    ['name' => 'PIPARMINTTU TEE', 'category' => 'default', 'unit' => 'kpl', 'amount' => 2, 'price' => 1.58],
                ],
            ]),
        ]);

        $receipt = app(AiReceiptParser::class)->parse($this->fixture());

        $this->assertSame(0.79, $receipt->items[0]->unitPrice);
    }

    public function test_a_discounted_line_keeps_the_shelf_unit_price(): void
    {
        // The model sends the net price plus the discount; unit_price is left out.
        Prism::fake([
            StructuredResponseFake::make()->withStructured([
                'shop' => 'Lidl',
                'date' => '2025-10-24',
                'total' => 1.43,
                'products' => [
                    ['name' => 'Italiansalaatti', 'category' => 'default', 'unit' => 'kpl', 'amount' => 1, 'price' => 1.43, 'discount_price' => 0.36],
                ],
            ]),
        ]);

        $item = app(AiReceiptParser::class)->parse($this->fixture())->items[0];

        $this->assertSame(1.79, $item->unitPrice);
        $this->assertSame(1.43, $item->price);
        $this->assertSame(0.36, $item->discountPrice);
        $this->assertTrue($item->isDiscount);
    }

    public function test_a_basket_wide_discount_line_keeps_its_negative_price(): void
    {
        Prism::fake([
            StructuredResponseFake::make()->withStructured([
                'shop' => 'Lidl',
                'date' => '2025-10-24',
                'total' => -1.0,
                'products' => [
                    ['name' => 'Lidl Plus -saastosi', 'category' => 'default', 'unit' => 'kpl', 'amount' => 1, 'unit_price' => 0, 'price' => -1.0, 'is_discount' => true, 'discount_price' => 1.0],
                ],
            ]),
        ]);

        $item = app(AiReceiptParser::class)->parse($this->fixture())->items[0];

        $this->assertSame(-1.0, $item->unitPrice);
        $this->assertSame(-1.0, $item->price);
        $this->assertTrue($item->isDiscount);
    }

    public function test_it_fails_on_an_unsupported_file_type(): void
    {
        Prism::fake([StructuredResponseFake::make()->withStructured([])]);

        $path = tempnam(sys_get_temp_dir(), 'receipt').'.txt';
        file_put_contents($path, 'not a receipt');

        $this->expectException(ReceiptParseException::class);

        try {
            app(AiReceiptParser::class)->parse($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_fails_when_the_model_returns_nothing(): void
    {
        Prism::fake([StructuredResponseFake::make()->withStructured([])]);

        $this->expectException(ReceiptParseException::class);

        app(AiReceiptParser::class)->parse($this->fixture());
    }

    public function test_it_works_with_a_non_anthropic_provider(): void
    {
        config(['receipts.provider' => 'gemini', 'receipts.model' => 'gemini-2.5-flash']);

        Prism::fake([
            StructuredResponseFake::make()->withStructured([
                'shop' => 'Lidl',
                'date' => '2025-09-21',
                'total' => 0.94,
                'products' => [
                    ['name' => 'KURKKU', 'category' => 'default', 'unit' => 'kg', 'amount' => 0.314, 'unit_price' => 2.99, 'price' => 0.94],
                ],
            ]),
        ]);

        $receipt = app(AiReceiptParser::class)->parse($this->fixture());

        $this->assertSame('Lidl', $receipt->shop);
        $this->assertSame('KURKKU', $receipt->items[0]->name);
    }

    public function test_it_rejects_an_unknown_provider_name(): void
    {
        config(['receipts.provider' => 'not-a-provider']);

        Prism::fake([StructuredResponseFake::make()->withStructured([])]);

        $this->expectException(ReceiptParseException::class);

        app(AiReceiptParser::class)->parse($this->fixture());
    }

    private function fixture(): string
    {
        return base_path('tests/Fixtures/prisma_receipt.pdf');
    }
}
