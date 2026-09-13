<?php

declare(strict_types=1);

namespace Tests\Feature\Shoplist;

use App\Models\Category;
use App\Models\History;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Unit;
use DefStudio\Telegraph\Facades\Telegraph;
use DefStudio\Telegraph\Models\TelegraphBot;
use DefStudio\Telegraph\Models\TelegraphChat;
use DefStudio\Telegraph\Telegraph as Api;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The /shoplist command, driven through the real webhook route.
 *
 * A warning about reading failures here: WebhookHandler::handle() catches every
 * Throwable and replies with a generic error string, so a genuine bug inside
 * the handler shows up as an assertion about the wrong message rather than a
 * stack trace. Always assert on the text you expect — never just that something
 * was sent — or a swallowed exception passes for a pass. Telegraph::fake()
 * ->dumpSentData() is the way out when a failure makes no sense.
 */
class ShoplistCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token';

    private const CHAT_ID = 424242;

    private TelegraphChat $chat;

    private Shop $shop;

    private Category $category;

    private Unit $unit;

    private int $line = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Telegraph::fake();

        $bot = TelegraphBot::create(['token' => self::TOKEN, 'name' => 'test']);
        $this->chat = $bot->chats()->create(['chat_id' => (string) self::CHAT_ID, 'name' => 'owner']);

        $this->shop = Shop::create(['name' => 'Prisma']);
        $this->category = Category::create(['name' => 'default']);
        $this->unit = Unit::create(['name' => 'kpl']);
    }

    public function test_it_says_so_when_there_is_nothing_to_predict_from(): void
    {
        $this->command('/shoplist');

        Telegraph::assertSent('Nothing to predict yet', exact: false);
    }

    public function test_it_says_so_when_nothing_is_due(): void
    {
        $this->bought('Coffee', [$this->daysAgo(40), $this->daysAgo(20), $this->daysAgo(1)]);

        $this->command('/shoplist');

        Telegraph::assertSent('Nothing is due right now. Tracking 1 products.', exact: false);
    }

    public function test_it_lists_an_overdue_product_with_a_button_of_its_own(): void
    {
        $milk = $this->bought('Milk', [$this->daysAgo(21), $this->daysAgo(14), $this->daysAgo(7)]);

        $this->command('/shoplist');

        Telegraph::assertSent('Milk', exact: false);
        Telegraph::assertSent('Overdue', exact: false);

        // One assertion pins the layout, the callback encoding and its size.
        Telegraph::assertSentData(Api::ENDPOINT_MESSAGE, [
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => '1. Milk', 'callback_data' => "action:snooze;p:{$milk->id}"]],
                    [['text' => 'Refresh', 'callback_data' => 'action:shoplist']],
                ],
            ],
        ]);
    }

    public function test_it_escapes_a_product_name_in_the_message_but_not_on_the_button(): void
    {
        $product = $this->bought("Ben & Jerry's <choc>", [
            $this->daysAgo(21), $this->daysAgo(14), $this->daysAgo(7),
        ]);

        $this->command('/shoplist');

        // The message is parsed as HTML, so a raw & is a 400 from Telegram.
        Telegraph::assertSent('Ben &amp; Jerry&#039;s &lt;choc&gt;', exact: false);

        // Button labels are not parsed, so the name goes on them as it is.
        Telegraph::assertSentData(Api::ENDPOINT_MESSAGE, [
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => "1. Ben & Jerry's <choc>", 'callback_data' => "action:snooze;p:{$product->id}"]],
                    [['text' => 'Refresh', 'callback_data' => 'action:shoplist']],
                ],
            ],
        ]);
    }

    public function test_tapping_a_button_snoozes_the_item_without_inventing_a_purchase(): void
    {
        $milk = $this->bought('Milk', [$this->daysAgo(21), $this->daysAgo(14), $this->daysAgo(7)]);
        $before = History::count();

        $this->tap($milk->id);

        $this->assertDatabaseHas('product_snoozes', ['product_id' => $milk->id]);
        $this->assertSame($before, History::count());
    }

    public function test_tapping_a_button_answers_the_callback_and_edits_the_list_in_place(): void
    {
        $milk = $this->bought('Milk', [$this->daysAgo(21), $this->daysAgo(14), $this->daysAgo(7)]);

        $this->tap($milk->id);

        Telegraph::assertRepliedWebhook('Skipped Milk until '.now()->format('j M').'.');
        Telegraph::assertSentData(Api::ENDPOINT_EDIT_MESSAGE, ['message_id' => 200], exact: false);
        Telegraph::assertNotSentData(Api::ENDPOINT_MESSAGE);
    }

    public function test_the_snoozed_item_is_gone_from_the_edited_list(): void
    {
        $milk = $this->bought('Milk', [$this->daysAgo(21), $this->daysAgo(14), $this->daysAgo(7)]);

        $this->tap($milk->id);

        Telegraph::assertSentData(Api::ENDPOINT_EDIT_MESSAGE, [
            'text' => 'Nothing is due right now.',
        ], exact: false);
        Telegraph::assertSentData(Api::ENDPOINT_EDIT_MESSAGE, [
            'text' => '1 skipped for now.',
        ], exact: false);
    }

    public function test_a_first_contact_chat_does_not_get_the_list(): void
    {
        $this->bought('Milk', [$this->daysAgo(21), $this->daysAgo(14), $this->daysAgo(7)]);

        $this->command('/shoplist', chatId: 999999);

        Telegraph::assertSent('Unknown command.', exact: false);
        Telegraph::assertNotSent('Milk', exact: false);
    }

    public function test_start_installs_the_menu_under_the_message_box(): void
    {
        $this->command('/start');

        Telegraph::assertSentData(Api::ENDPOINT_MESSAGE, [
            'reply_markup' => [
                'keyboard' => [
                    [['text' => 'Shopping list'], ['text' => 'Receipt']],
                ],
                'is_persistent' => true,
                'resize_keyboard' => true,
            ],
        ]);
    }

    public function test_receipt_asks_for_a_file_and_keeps_the_menu(): void
    {
        $this->command('/receipt');

        Telegraph::assertSent('Send the receipt now', exact: false);
        Telegraph::assertSentData(Api::ENDPOINT_MESSAGE, [
            'reply_markup' => [
                'keyboard' => [
                    [['text' => 'Shopping list'], ['text' => 'Receipt']],
                ],
                'is_persistent' => true,
                'resize_keyboard' => true,
            ],
        ]);
    }

    public function test_the_shopping_list_button_runs_the_command(): void
    {
        $this->bought('Milk', [$this->daysAgo(21), $this->daysAgo(14), $this->daysAgo(7)]);

        // A reply-keyboard tap arrives as a plain message, not as a command.
        $this->text('Shopping list');

        Telegraph::assertSent('Milk', exact: false);
        Telegraph::assertSent('Overdue', exact: false);
    }

    public function test_the_receipt_button_runs_the_command(): void
    {
        $this->text('Receipt');

        Telegraph::assertSent('Send the receipt now', exact: false);
    }

    public function test_ordinary_text_still_gets_the_upload_hint(): void
    {
        $this->text('hello');

        Telegraph::assertSent('Send me a receipt: a PDF file or a photo.');
    }

    public function test_a_bare_snooze_command_is_harmless(): void
    {
        $this->command('/snooze');

        Telegraph::assertSent('Nothing to skip.', exact: false);
        $this->assertDatabaseCount('product_snoozes', 0);
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

    private function text(string $body): void
    {
        $this->postJson('/telegraph/'.self::TOKEN.'/webhook', [
            'update_id' => 3,
            'message' => [
                'message_id' => 101,
                'from' => ['id' => self::CHAT_ID, 'is_bot' => false, 'first_name' => 'Owner'],
                'chat' => ['id' => self::CHAT_ID, 'type' => 'private'],
                'date' => now()->timestamp,
                'text' => $body,
            ],
        ])->assertNoContent();
    }

    private function tap(int $productId): void
    {
        $this->postJson('/telegraph/'.self::TOKEN.'/webhook', [
            'update_id' => 2,
            'callback_query' => [
                'id' => '1122334455',
                'from' => ['id' => self::CHAT_ID, 'is_bot' => false, 'first_name' => 'Owner'],
                'message' => [
                    'message_id' => 200,
                    'from' => ['id' => 1, 'is_bot' => true, 'first_name' => 'bot'],
                    'chat' => ['id' => self::CHAT_ID, 'type' => 'private'],
                    'date' => now()->timestamp,
                    'text' => 'Time to buy',
                ],
                'data' => "action:snooze;p:{$productId}",
            ],
        ])->assertNoContent();
    }

    /**
     * @param  list<string>  $dates
     */
    private function bought(string $name, array $dates): Product
    {
        $product = Product::create([
            'name' => $name,
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
        ]);

        foreach ($dates as $date) {
            History::create([
                'product_id' => $product->id,
                'shop_id' => $this->shop->id,
                'amount' => 1,
                'price' => 1.5,
                'total' => 1.5,
                'date' => $date,
                'filename' => 'receipt_'.(++$this->line).'.pdf',
            ]);
        }

        return $product;
    }

    private function daysAgo(int $days): string
    {
        return now()->subDays($days)->toDateString();
    }
}
