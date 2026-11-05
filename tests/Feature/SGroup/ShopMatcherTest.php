<?php

declare(strict_types=1);

namespace Tests\Feature\SGroup;

use App\Exceptions\ReceiptParseException;
use App\Models\Shop;
use App\Services\SGroup\ShopMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopMatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['PRISMA', 'Lidl', 'S-Market', 'Sale'] as $name) {
            Shop::create(['name' => $name]);
        }
    }

    public function test_the_chain_is_matched_from_the_store_name_ignoring_case(): void
    {
        $matcher = new ShopMatcher;

        $this->assertSame('PRISMA', $matcher->match('Prisma Itäkeskus'));
        $this->assertSame('S-Market', $matcher->match('S-market Söderkulla'));
    }

    public function test_the_longest_matching_shop_wins(): void
    {
        Shop::create(['name' => 'Prisma Itäkeskus']);

        $this->assertSame('Prisma Itäkeskus', (new ShopMatcher)->match('Prisma Itäkeskus'));
    }

    public function test_a_shop_must_match_a_whole_word(): void
    {
        $this->expectException(ReceiptParseException::class);

        (new ShopMatcher)->match('Salemarket Kallio');
    }

    public function test_an_unknown_chain_is_an_error_not_a_new_shop(): void
    {
        try {
            (new ShopMatcher)->match('Alepa Kamppi');
            $this->fail('Expected an exception.');
        } catch (ReceiptParseException $e) {
            $this->assertStringContainsString('Alepa Kamppi', $e->getMessage());
        }

        $this->assertSame(4, Shop::count());
    }
}
