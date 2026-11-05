<?php

declare(strict_types=1);

namespace Tests\Feature\SGroup;

use App\Jobs\ImportSGroupReceipt;
use App\Jobs\PushReceiptToWallet;
use App\Models\Category;
use App\Models\History;
use App\Models\Product;
use App\Models\SGroupSession;
use App\Models\Shop;
use App\Models\Unit;
use App\Services\SGroup\SGroupSync;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Prism\Prism\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Tests\TestCase;

/**
 * The whole path: list → queue → detail → read → save → Wallet, against a
 * faked S-Etukortti API. The queue is `sync` in tests, so the import jobs run
 * inline; only the Wallet push is faked.
 */
class SGroupSyncTest extends TestCase
{
    use RefreshDatabase;

    private const ID = '2195275_64051211';

    protected function setUp(): void
    {
        parent::setUp();

        // One month of range, so each run is exactly one list request.
        CarbonImmutable::setTestNow('2026-10-31 12:00:00');

        Queue::fake([PushReceiptToWallet::class]);

        Shop::create(['name' => 'S-Market']);
        Category::create(['name' => 'default']);
        $dairy = Category::create(['name' => 'Dairy']);
        Category::create(['name' => 'Fruit']);

        // Known before the sync: keeps its category without asking the model.
        Product::create([
            'name' => 'RAEJUUSTO',
            'category_id' => $dairy->id,
            'unit_id' => Unit::create(['name' => 'kpl'])->id,
            'count' => 1,
        ]);

        SGroupSession::create(['session_id' => 'sid-secret', 'xsrf_token' => 'xsrf-secret']);
    }

    public function test_a_new_receipt_is_fetched_read_and_saved(): void
    {
        $this->fakeApi();
        Prism::fake([StructuredResponseFake::make()->withStructured([
            'products' => [['name' => 'BANAANI CHIQUITA', 'category' => 'Fruit']],
        ])]);

        $queued = app(SGroupSync::class)->run($this->october());

        $this->assertSame(1, $queued);

        $filename = 's-etukortti:'.self::ID;
        $this->assertSame(11, History::where('filename', $filename)->count());
        $this->assertSame(27.95, round((float) History::where('filename', $filename)->sum('total'), 2));

        $banana = History::where('filename', $filename)
            ->whereHas('product', fn ($q) => $q->where('name', 'BANAANI CHIQUITA'))->first();
        $this->assertEqualsWithDelta(0.761, (float) $banana->amount, 0.0001);
        $this->assertEqualsWithDelta(1.88, (float) $banana->price, 0.0001);
        $this->assertSame('Fruit', Product::where('name', 'BANAANI CHIQUITA')->first()->category->name);
        $this->assertSame('Dairy', Product::where('name', 'RAEJUUSTO')->first()->category->name);

        $this->assertSame('2026-10-22', substr((string) History::where('filename', $filename)->value('date'), 0, 10));

        Queue::assertPushed(PushReceiptToWallet::class, 1);

        // Cookies and the double-submit header went out as the site sends them.
        Http::assertSent(fn (Request $r): bool => $r->hasHeader('x-csrf-token', 'xsrf-secret')
            && str_contains($r->header('Cookie')[0], 'sessionId=sid-secret'));
    }

    public function test_a_saved_receipt_is_not_fetched_again(): void
    {
        $this->fakeApi();
        Prism::fake([StructuredResponseFake::make()->withStructured(['products' => []])]);

        app(SGroupSync::class)->run($this->october());
        $this->assertSame(0, app(SGroupSync::class)->run());

        Http::assertSentCount(3); // list, detail, list
    }

    public function test_a_receipt_uploaded_earlier_as_a_pdf_is_not_imported_twice(): void
    {
        $this->fakeApi();

        History::create([
            'product_id' => Product::first()->id,
            'shop_id' => Shop::first()->id,
            'date' => '2026-10-22',
            'price' => 1.89, 'amount' => 1, 'total' => 1.89,
            'filename' => self::ID.'.pdf',
        ]);

        $this->assertSame(0, app(SGroupSync::class)->run($this->october()));
        $this->assertSame(0, History::where('filename', 's-etukortti:'.self::ID)->count());
    }

    public function test_a_refused_session_is_marked_expired_and_not_used_again(): void
    {
        Http::fake(['*' => Http::response('', 401)]);

        $this->assertNull(app(SGroupSync::class)->run());
        $this->assertNotNull(SGroupSession::first()->expired_at);
        $this->assertNull(SGroupSession::active());

        // The next scheduled run makes no request at all.
        $this->assertNull(app(SGroupSync::class)->run());
        Http::assertSentCount(1);
    }

    /**
     * What the API really answers for a dead session — and for a mangled
     * sessionId (copied URL-decoded) — is not a 401 but an error envelope.
     */
    public function test_session_errors_in_the_envelope_count_as_expired(): void
    {
        foreach ([[400, 'BAD_REQUEST', 'Failed to get session by id'], [200, 'BAD_REQUEST', 'Failed to get session by id'], [500, 'INTERNAL_ERROR', 'Failed to decrypt the string']] as [$status, $code, $message]) {
            SGroupSession::query()->delete();
            SGroupSession::create(['session_id' => 'old', 'xsrf_token' => 'old']);

            Http::fake(['*' => Http::response([
                'errors' => [[
                    'code' => $code,
                    'field' => null,
                    'fieldErrorCode' => null,
                    'errorMessage' => $message,
                ]],
                'correlationId' => 'x',
                'data' => null,
                'status' => 'ERROR',
            ], $status)]);

            $this->assertNull(app(SGroupSync::class)->run(), "HTTP {$status}");
            $this->assertNotNull(SGroupSession::sole()->expired_at, "HTTP {$status}");
        }
    }

    public function test_an_unclear_receipt_is_read_by_the_model_instead(): void
    {
        $detail = $this->detail();
        $detail['data']['receipt']['receiptData'] = str_replace(
            "*RAEJUUSTO                            1,89 *\n",
            "*RAEJUUSTO                            1,89 *\n*ALENNUS                             -0,20 *\n",
            $detail['data']['receipt']['receiptData'],
        );
        $this->fakeApi($detail);

        Prism::fake([StructuredResponseFake::make()->withStructured([
            'shop' => 'S-Market',
            'date' => '2026-10-22',
            'total' => 27.75,
            'products' => [[
                'name' => 'RAEJUUSTO', 'category' => 'Dairy', 'unit' => 'kpl', 'amount' => 1,
                'unit_price' => 1.89, 'price' => 1.69, 'is_discount' => true, 'discount_price' => 0.20,
            ]],
        ])]);

        app(SGroupSync::class)->run($this->october());

        $line = History::where('filename', 's-etukortti:'.self::ID)->sole();
        $this->assertEqualsWithDelta(1.69, (float) $line->total, 0.0001);
        $this->assertSame(1, (int) $line->is_discount);
    }

    public function test_the_secrets_are_stored_encrypted(): void
    {
        $raw = DB::table('sgroup_sessions')->first();

        $this->assertNotSame('sid-secret', $raw->session_id);
        $this->assertSame('sid-secret', SGroupSession::first()->session_id);
    }

    public function test_the_job_alone_skips_a_receipt_already_saved(): void
    {
        $this->fakeApi();
        Prism::fake([StructuredResponseFake::make()->withStructured(['products' => []])]);

        ImportSGroupReceipt::dispatchSync(self::ID);
        ImportSGroupReceipt::dispatchSync(self::ID);

        Http::assertSentCount(1);
    }

    private function october(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-01');
    }

    /**
     * @param  array<string, mixed>|null  $detail
     */
    private function fakeApi(?array $detail = null): void
    {
        Http::fake([
            'api.s-etukortti.fi/api/v1/receipts/*' => Http::response($detail ?? $this->detail()),
            'api.s-etukortti.fi/api/v1/receipts?*' => Http::response([
                'data' => ['receipts' => [[
                    'receiptId' => self::ID,
                    'placeOfBusinessCode' => '653462903',
                    'placeOfBusinessName' => 'S-market Söderkulla',
                    'transactionStart' => '2026-10-22T19:20:50+03:00',
                    'totalAmount' => 27.95,
                ]]],
                'correlationId' => '',
                'errors' => null,
                'status' => 'OK',
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function detail(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/sgroup_receipt.json')), true);
    }
}
