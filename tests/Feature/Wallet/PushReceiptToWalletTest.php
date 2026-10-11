<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Exceptions\WalletException;
use App\Jobs\PushReceiptToWallet;
use App\Models\Shop;
use App\Models\WalletRecord;
use App\Services\Wallet\BudgetBakersClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushReceiptToWalletTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'budgetbakers.enabled' => true,
            'budgetbakers.token' => 'test-token',
            'budgetbakers.base_url' => 'https://rest.budgetbakers.com/wallet',
        ]);
    }

    public function test_it_creates_one_expense_from_the_shop_template(): void
    {
        Http::fake($this->wallet());

        $shop = $this->shop();

        $this->job()->handle(app(BudgetBakersClient::class));

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/v1/api/records') || $request->method() !== 'POST') {
                return false;
            }

            // The endpoint takes a batch, so a single record is still an array.
            $record = $request->data()[0];

            return $record['accountId'] === 'acc-1'
                && $record['categoryId'] === 'cat-1'
                && $record['amount'] === ['value' => -47.31]
                && $record['counterParty'] === 'Prisma Kaleva'
                && str_starts_with($record['recordDate'], '2026-09-02T00:00:00')
                && str_contains($record['note'], '23 items')
                // Wallet rejects a currency that differs from the account's.
                && ! isset($record['amount']['currencyCode']);
        });

        $this->assertDatabaseHas('wallet_records', [
            'filename' => 'receipt.pdf',
            'shop_id' => $shop->id,
            'status' => WalletRecord::CREATED,
            'wallet_record_id' => 'rec-99',
        ]);
    }

    /**
     * Wallet's text filters are prefixed. A bare `?name=Cash` is rejected with
     * HTTP 400 "filter must start with 'eq.', 'contains.', or 'contains-i.'".
     */
    public function test_name_lookups_send_a_prefixed_filter(): void
    {
        Http::fake($this->wallet());

        $this->shop();

        $this->job()->handle(app(BudgetBakersClient::class));

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/v1/api/accounts')
                && $request['name'] === 'contains-i.Cash';
        });

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/v1/api/categories')
                && $request['name'] === 'contains-i.Groceries';
        });
    }

    /**
     * The prefixed filter is a substring search, so an account whose name only
     * starts with what the shop asked for must not be accepted.
     */
    public function test_a_partial_name_match_is_not_accepted(): void
    {
        Http::fake([
            '*/v1/api/accounts*' => Http::response(['accounts' => [
                ['id' => 'acc-9', 'name' => 'Cash reserve'],
                ['id' => 'acc-8', 'name' => 'Petty cash box'],
            ]]),
            '*/v1/api/records' => Http::response(['results' => [['id' => 'rec-99']]]),
        ]);

        $this->shop();

        $this->job()->handle(app(BudgetBakersClient::class));

        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/api/records'));
        $this->assertSame(WalletRecord::SKIPPED, WalletRecord::firstWhere('filename', 'receipt.pdf')->status);
    }

    public function test_a_shop_without_a_template_is_skipped_without_calling_wallet(): void
    {
        Http::fake($this->wallet());

        Shop::create(['name' => 'Prisma']);

        $this->job()->handle(app(BudgetBakersClient::class));

        Http::assertNothingSent();

        $record = WalletRecord::firstWhere('filename', 'receipt.pdf');
        $this->assertSame(WalletRecord::SKIPPED, $record->status);
        $this->assertStringContainsString('No Wallet template', $record->error);
    }

    public function test_an_account_name_that_wallet_does_not_know_is_skipped(): void
    {
        Http::fake([
            '*/v1/api/accounts*' => Http::response(['accounts' => []]),
            '*/v1/api/records' => Http::response(['results' => [['id' => 'rec-99']]]),
        ]);

        $this->shop();

        $this->job()->handle(app(BudgetBakersClient::class));

        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/api/records'));

        $record = WalletRecord::firstWhere('filename', 'receipt.pdf');
        $this->assertSame(WalletRecord::SKIPPED, $record->status);
        $this->assertStringContainsString('No Wallet account named "Cash"', $record->error);
    }

    public function test_a_receipt_already_in_wallet_is_not_sent_again(): void
    {
        Http::fake($this->wallet());

        $shop = $this->shop();

        WalletRecord::create([
            'filename' => 'receipt.pdf',
            'shop_id' => $shop->id,
            'amount' => 47.31,
            'record_date' => '2026-09-02',
            'wallet_record_id' => 'rec-01',
            'status' => WalletRecord::CREATED,
        ]);

        $this->job()->handle(app(BudgetBakersClient::class));

        Http::assertNothingSent();
        $this->assertSame(1, WalletRecord::count());
        $this->assertSame('rec-01', WalletRecord::first()->wallet_record_id);
    }

    public function test_a_rate_limit_releases_the_job_and_leaves_the_row_pending(): void
    {
        Http::fake([
            '*/v1/api/accounts*' => Http::response(['accounts' => [['id' => 'acc-1', 'name' => 'Cash']]]),
            '*/v1/api/categories*' => Http::response(['categories' => [['id' => 'cat-1', 'name' => 'Groceries']]]),
            '*/v1/api/records' => Http::response(['error' => 'too many'], 429, ['Retry-After' => '30']),
        ]);

        $this->shop();

        $job = $this->jobRecordingRelease();

        $job->handle(app(BudgetBakersClient::class));

        $this->assertSame(30, $job->released);
        $this->assertSame(WalletRecord::PENDING, WalletRecord::firstWhere('filename', 'receipt.pdf')->status);
    }

    public function test_a_rejected_record_fails_the_job_and_is_recorded(): void
    {
        Http::fake([
            '*/v1/api/accounts*' => Http::response(['accounts' => [['id' => 'acc-1', 'name' => 'Cash']]]),
            '*/v1/api/categories*' => Http::response(['categories' => [['id' => 'cat-1', 'name' => 'Groceries']]]),
            '*/v1/api/records' => Http::response([
                'results' => [['inputIndex' => 0, 'error' => 'create permission not granted for records', 'errorType' => 'client_error']],
            ], 207),
        ]);

        $this->shop();

        $job = $this->job();

        try {
            $job->handle(app(BudgetBakersClient::class));
            $this->fail('Expected a WalletException.');
        } catch (WalletException $e) {
            $job->failed($e);
        }

        $record = WalletRecord::firstWhere('filename', 'receipt.pdf');
        $this->assertSame(WalletRecord::FAILED, $record->status);
        $this->assertStringContainsString('create permission not granted', $record->error);
    }

    public function test_it_does_nothing_when_the_integration_is_off(): void
    {
        Http::fake($this->wallet());
        config(['budgetbakers.enabled' => false]);

        $this->shop();

        $this->job()->handle(app(BudgetBakersClient::class));

        Http::assertNothingSent();
        $this->assertSame(0, WalletRecord::count());
    }

    public function test_it_does_nothing_without_a_token(): void
    {
        Http::fake($this->wallet());
        config(['budgetbakers.token' => null]);

        $this->shop();

        $this->job()->handle(app(BudgetBakersClient::class));

        Http::assertNothingSent();
        $this->assertSame(0, WalletRecord::count());
    }

    private function shop(): Shop
    {
        return Shop::create([
            'name' => 'Prisma',
            'wallet_account' => 'Cash',
            'wallet_category' => 'Groceries',
            'wallet_payee' => 'Prisma Kaleva',
        ]);
    }

    private function job(): PushReceiptToWallet
    {
        return new PushReceiptToWallet('receipt.pdf', 'Prisma', '2026-09-02', 47.31, 23);
    }

    /**
     * release() is a no-op when handle() is called directly, since there is no
     * queue job behind it, so record the delay instead of letting it vanish.
     */
    private function jobRecordingRelease(): PushReceiptToWallet
    {
        return new class('receipt.pdf', 'Prisma', '2026-09-02', 47.31, 23) extends PushReceiptToWallet
        {
            public ?int $released = null;

            public function release($delay = 0)
            {
                $this->released = (int) $delay;

                return null;
            }
        };
    }

    /** @return array<string, \Closure|Response> */
    private function wallet(): array
    {
        return [
            '*/v1/api/accounts*' => Http::response(['accounts' => [['id' => 'acc-1', 'name' => 'Cash', 'currencyCode' => 'EUR']]]),
            '*/v1/api/categories*' => Http::response(['categories' => [['id' => 'cat-1', 'name' => 'Groceries']]]),
            '*/v1/api/records' => Http::response(['results' => [['inputIndex' => 0, 'id' => 'rec-99']]]),
        ];
    }
}
