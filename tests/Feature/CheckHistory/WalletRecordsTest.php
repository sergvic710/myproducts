<?php

declare(strict_types=1);

namespace Tests\Feature\CheckHistory;

use App\Exceptions\WalletException;
use App\Services\Wallet\BudgetBakersClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Reading records out of Wallet.
 *
 * Keep test_it_stops_when_the_offset_does_not_advance near the top: if that
 * guard regresses the paginator spins forever, and a hanging suite is much
 * harder to read than a failing one.
 */
class WalletRecordsTest extends TestCase
{
    private BudgetBakersClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'budgetbakers.token' => 'test-token',
            'budgetbakers.base_url' => 'https://rest.budgetbakers.com/wallet',
            'budgetbakers.max_pages' => 10,
        ]);

        $this->client = new BudgetBakersClient;
    }

    public function test_it_stops_when_the_offset_does_not_advance(): void
    {
        // A server echoing the same offset back is a stream of valid 200s, so
        // nothing but this guard ends the walk.
        Http::fake(['*' => Http::response(['records' => [['id' => 'r1']], 'nextOffset' => 0])]);

        $this->assertCount(1, $this->records());
        Http::assertSentCount(1);
    }

    public function test_it_sends_a_bare_category_id_and_a_prefixed_date(): void
    {
        Http::fake(['*' => Http::response(['records' => [], 'nextOffset' => null])]);

        $this->client->expenseRecords('cat-1', CarbonImmutable::parse('2026-09-01'));

        Http::assertSent(function (Request $request): bool {
            // The whole point. "eq.cat-1" comes back 200 with zero records,
            // so this failing silently is exactly what it looks like when the
            // feature reports "nothing to do" and is wrong.
            $this->assertSame('cat-1', $request['categoryId']);
            $this->assertStringStartsWith('gte.', (string) $request['recordDate']);
            $this->assertSame('expense', $request['recordType']);
            $this->assertSame(200, (int) $request['limit']);

            return true;
        });
    }

    public function test_it_follows_the_cursor_until_it_runs_out(): void
    {
        Http::fakeSequence()
            ->push(['records' => [['id' => 'a']], 'nextOffset' => 200])
            ->push(['records' => [['id' => 'b']], 'nextOffset' => null]);

        $records = $this->records();

        $this->assertSame(['a', 'b'], array_column($records, 'id'));
        Http::assertSentCount(2);
    }

    public function test_it_stops_at_the_page_cap(): void
    {
        config(['budgetbakers.max_pages' => 3]);

        $offset = 0;
        Http::fake(function () use (&$offset) {
            $offset += 200;

            return Http::response(['records' => [['id' => 'r'.$offset]], 'nextOffset' => $offset]);
        });

        $this->assertCount(3, $this->records());
        Http::assertSentCount(3);
    }

    public function test_an_empty_page_ends_the_walk(): void
    {
        Http::fake(['*' => Http::response(['records' => [], 'nextOffset' => 200])]);

        $this->assertSame([], $this->records());
        Http::assertSentCount(1);
    }

    public function test_a_rate_limit_still_becomes_a_wallet_exception(): void
    {
        // Proves reads go through send() rather than around it.
        Http::fake(['*' => Http::response(['error' => 'too many'], 429, ['Retry-After' => '30'])]);

        try {
            $this->records();
            $this->fail('Expected a WalletException.');
        } catch (WalletException $e) {
            $this->assertSame(30, $e->retryAfter);
        }
    }

    public function test_it_throws_without_a_token(): void
    {
        config(['budgetbakers.token' => null]);
        Http::fake();

        $this->expectException(WalletException::class);

        $this->records();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function records(): array
    {
        return $this->client->expenseRecords('cat-1', CarbonImmutable::parse('2026-09-01'));
    }
}
