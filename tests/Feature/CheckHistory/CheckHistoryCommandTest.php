<?php

declare(strict_types=1);

namespace Tests\Feature\CheckHistory;

use App\Models\Category;
use App\Models\History;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Unit;
use DefStudio\Telegraph\Facades\Telegraph;
use DefStudio\Telegraph\Models\TelegraphBot;
use DefStudio\Telegraph\Support\Testing\Fakes\TelegraphFake;
use DefStudio\Telegraph\Telegraph as Api;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * /checkhistory through the real webhook.
 *
 * WebhookHandler::handle() swallows every Throwable and replies with a generic
 * string, so always assert on the text you expect — asserting only that
 * "something was sent" lets a real bug pass.
 */
class CheckHistoryCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token';

    private const CHAT_ID = 424242;

    private Category $category;

    private Unit $unit;

    private int $line = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Telegraph::fake();

        config([
            'budgetbakers.token' => 'wallet-token',
            'budgetbakers.base_url' => 'https://rest.budgetbakers.com/wallet',
            'budgetbakers.enabled' => true,
        ]);

        $bot = TelegraphBot::create(['token' => self::TOKEN, 'name' => 'test']);
        $bot->chats()->create(['chat_id' => (string) self::CHAT_ID, 'name' => 'owner']);

        $this->category = Category::create(['name' => 'default']);
        $this->unit = Unit::create(['name' => 'kpl']);
    }

    public function test_it_offers_the_range_buttons(): void
    {
        $this->command('/checkhistory');

        Telegraph::assertSent('how far back', exact: false);
        Telegraph::assertSentData(Api::ENDPOINT_MESSAGE, [
            'reply_markup' => [
                'inline_keyboard' => [[
                    ['text' => '7 days', 'callback_data' => 'action:checkdays;d:7'],
                    ['text' => '14 days', 'callback_data' => 'action:checkdays;d:14'],
                    ['text' => '30 days', 'callback_data' => 'action:checkdays;d:30'],
                ]],
            ],
        ]);
    }

    public function test_tapping_a_range_reports_the_missing_receipts_in_place(): void
    {
        Shop::create(['name' => 'Lidl']);
        $this->wallet([$this->record('Lidl', 1, 24.90)]);

        $this->tap(7);

        Telegraph::assertSentData(Api::ENDPOINT_EDIT_MESSAGE, ['text' => 'No receipt (1)'], exact: false);
        Telegraph::assertSentData(Api::ENDPOINT_EDIT_MESSAGE, ['text' => 'Lidl'], exact: false);
        Telegraph::assertSentData(Api::ENDPOINT_EDIT_MESSAGE, ['message_id' => 200], exact: false);
        Telegraph::assertNotSentData(Api::ENDPOINT_MESSAGE);
    }

    public function test_tapping_a_range_answers_the_callback_first(): void
    {
        Shop::create(['name' => 'Lidl']);
        $this->wallet([]);

        $this->tap(7);

        Telegraph::assertRepliedWebhook('Checking Wallet…');
    }

    public function test_it_reports_an_unknown_payee_separately(): void
    {
        Shop::create(['name' => 'Lidl']);
        $this->wallet([$this->record('Rimi', 1, 9.00), $this->record('Rimi', 2, 3.00)]);

        $this->tap(7);

        Telegraph::assertSentData(Api::ENDPOINT_EDIT_MESSAGE, ['text' => 'Unknown payee (2)'], exact: false);
        Telegraph::assertSentData(Api::ENDPOINT_EDIT_MESSAGE, ['text' => 'Rimi — 2 expenses'], exact: false);
    }

    public function test_it_says_so_when_everything_has_a_receipt(): void
    {
        $shop = Shop::create(['name' => 'Lidl']);
        $this->receipt($shop, now()->subDay()->toDateString());
        $this->wallet([$this->record('Lidl', 1, 24.90)]);

        $this->tap(7);

        Telegraph::assertSentData(Api::ENDPOINT_EDIT_MESSAGE, ['text' => 'have a receipt'], exact: false);
    }

    public function test_it_says_so_when_wallet_has_nothing(): void
    {
        $this->wallet([]);

        $this->tap(7);

        Telegraph::assertSentData(Api::ENDPOINT_EDIT_MESSAGE, ['text' => 'No Groceries expenses'], exact: false);
    }

    public function test_it_still_runs_when_pushing_is_disabled(): void
    {
        // BUDGETBAKERS_ENABLED gates writing. Reading is most useful precisely
        // when writing is off, so this must not be gated by it.
        config(['budgetbakers.enabled' => false]);
        Shop::create(['name' => 'Lidl']);
        $this->wallet([$this->record('Lidl', 1, 24.90)]);

        $this->tap(7);

        Telegraph::assertSentData(Api::ENDPOINT_EDIT_MESSAGE, ['text' => 'No receipt (1)'], exact: false);
    }

    public function test_a_range_that_is_not_offered_is_refused(): void
    {
        Http::fake();

        $this->tap(999);

        Telegraph::assertRepliedWebhook('That range is no longer offered. Send /checkhistory.');
        Http::assertNothingSent();
    }

    public function test_it_says_so_when_the_token_is_missing(): void
    {
        config(['budgetbakers.token' => null]);
        Http::fake();

        $this->tap(7);

        Telegraph::assertSentData(Api::ENDPOINT_EDIT_MESSAGE, ['text' => 'Wallet is not connected'], exact: false);
        Http::assertNothingSent();
    }

    public function test_wallet_being_unreachable_is_reported_not_thrown(): void
    {
        Http::fake([
            '*/v1/api/categories*' => Http::response(['categories' => [['id' => 'cat-1', 'name' => 'Groceries']]]),
            '*/v1/api/records*' => Http::response(['error' => 'boom'], 500),
        ]);

        $this->tap(7);

        Telegraph::assertSentData(Api::ENDPOINT_EDIT_MESSAGE, ['text' => 'Could not check Wallet'], exact: false);
    }

    public function test_a_missing_groceries_category_is_reported(): void
    {
        Http::fake([
            '*/v1/api/categories*' => Http::response(['categories' => []]),
            '*/v1/api/records*' => Http::response(['records' => [], 'nextOffset' => null]),
        ]);

        $this->tap(7);

        Telegraph::assertSentData(Api::ENDPOINT_EDIT_MESSAGE, ['text' => 'Groceries'], exact: false);
        Telegraph::assertSentData(Api::ENDPOINT_EDIT_MESSAGE, ['text' => 'Could not check Wallet'], exact: false);
    }

    public function test_a_payee_is_escaped(): void
    {
        $this->wallet([$this->record("Ben & Jerry's", 1, 5.00)]);

        $this->tap(7);

        Telegraph::assertSentData(Api::ENDPOINT_EDIT_MESSAGE, [
            'text' => 'Ben &amp; Jerry&#039;s',
        ], exact: false);
    }

    public function test_a_long_report_stays_inside_the_telegram_limit(): void
    {
        Shop::create(['name' => 'Lidl']);

        $records = [];
        for ($i = 1; $i <= 300; $i++) {
            $records[] = $this->record('Lidl', ($i % 7) + 1, 10.00);
        }
        $this->wallet($records);

        $this->tap(7);

        Telegraph::assertSentData(Api::ENDPOINT_EDIT_MESSAGE, ['text' => 'and 260 more.'], exact: false);

        // The bound that actually matters: Telegram rejects anything longer.
        $this->assertLessThanOrEqual(4096, mb_strlen($this->lastSentText()));
    }

    public function test_the_character_guard_trims_a_report_the_line_cap_would_not(): void
    {
        // The line cap counts lines and Telegram counts characters, so a
        // handful of very long payee names still has to be caught.
        config(['checkhistory.char_limit' => 300]);

        // The name has to match a shop, or the expenses fold into a single
        // unknown-payee line and there is nothing long to trim.
        $name = trim(str_repeat('Very Long Shop Name ', 5));
        Shop::create(['name' => $name]);

        $records = [];
        for ($i = 1; $i <= 12; $i++) {
            $records[] = $this->record($name, ($i % 7) + 1, 10.00);
        }
        $this->wallet($records);

        $this->tap(7);

        $text = $this->lastSentText();

        $this->assertStringContainsString('trimmed to fit.', $text);
        $this->assertLessThanOrEqual(400, mb_strlen($text));
    }

    /**
     * The fake keeps what it sent in a protected static; there is no accessor,
     * and the length of the real message is the only way to prove the cap.
     */
    private function lastSentText(): string
    {
        $sent = (new \ReflectionClass(TelegraphFake::class))->getStaticPropertyValue('sentMessages');

        $edits = array_values(array_filter(
            $sent,
            static fn (array $m): bool => $m['endpoint'] === Api::ENDPOINT_EDIT_MESSAGE,
        ));

        $this->assertNotEmpty($edits, 'No edited message was sent.');

        return (string) end($edits)['data']['text'];
    }

    public function test_an_unknown_chat_gets_nothing(): void
    {
        Shop::create(['name' => 'Lidl']);
        $this->wallet([$this->record('Lidl', 1, 24.90)]);

        $this->command('/checkhistory', chatId: 999999);

        Telegraph::assertSent('Unknown command.', exact: false);
        Telegraph::assertNotSent('how far back', exact: false);
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function wallet(array $records): void
    {
        Http::fake([
            '*/v1/api/categories*' => Http::response(['categories' => [['id' => 'cat-1', 'name' => 'Groceries']]]),
            '*/v1/api/records*' => Http::response(['records' => $records, 'nextOffset' => null]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function record(string $payee, int $daysAgo, float $amount): array
    {
        return [
            'id' => 'rec-'.(++$this->line),
            'counterParty' => $payee,
            'recordDate' => now()->subDays($daysAgo)->format('Y-m-d').'T12:00:00.000Z',
            'amount' => ['value' => -$amount, 'currencyCode' => 'EUR'],
        ];
    }

    private function receipt(Shop $shop, string $date): void
    {
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
            'filename' => 'receipt_'.$this->line.'.pdf',
        ]);
    }

    private function command(string $text, ?int $chatId = null): void
    {
        $chatId ??= self::CHAT_ID;

        $this->postJson('/telegraph/'.self::TOKEN.'/webhook', [
            'update_id' => 1,
            'message' => [
                'message_id' => 100,
                'from' => ['id' => $chatId, 'is_bot' => false, 'first_name' => 'Owner'],
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'date' => now()->timestamp,
                'text' => $text,
                'entities' => [['offset' => 0, 'length' => strlen($text), 'type' => 'bot_command']],
            ],
        ])->assertNoContent();
    }

    private function tap(int $days): void
    {
        $this->postJson('/telegraph/'.self::TOKEN.'/webhook', [
            'update_id' => 2,
            'callback_query' => [
                // Numeric: CallbackQuery::$id is typed int, and a non-numeric
                // string throws inside the handler's catch-all.
                'id' => '1122334455',
                'from' => ['id' => self::CHAT_ID, 'is_bot' => false, 'first_name' => 'Owner'],
                'message' => [
                    'message_id' => 200,
                    'from' => ['id' => 1, 'is_bot' => true, 'first_name' => 'bot'],
                    'chat' => ['id' => self::CHAT_ID, 'type' => 'private'],
                    'date' => now()->timestamp,
                    'text' => 'Wallet against receipts',
                ],
                'data' => "action:checkdays;d:{$days}",
            ],
        ])->assertNoContent();
    }
}
