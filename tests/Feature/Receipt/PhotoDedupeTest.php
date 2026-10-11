<?php

declare(strict_types=1);

namespace Tests\Feature\Receipt;

use App\Jobs\ProcessReceipt;
use App\Models\Category;
use App\Models\History;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Unit;
use DefStudio\Telegraph\Facades\Telegraph;
use DefStudio\Telegraph\Models\TelegraphBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Naming a photo after its contents rather than its Telegram file id.
 *
 * Telegram issues a fresh file id every time the same picture is sent, so the
 * old id-derived name let one receipt be saved twice — which is exactly what
 * happened to two Lidl photos. `filename` is the dedupe key everywhere, so
 * making it content-derived fixes the whole chain at once.
 *
 * Telegraph's fake does not actually download anything, so each test writes the
 * bytes where the handler will look for them.
 */
class PhotoDedupeTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token';

    private const CHAT_ID = 424242;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        Telegraph::fake();
        Queue::fake();

        $bot = TelegraphBot::create(['token' => self::TOKEN, 'name' => 'test']);
        $bot->chats()->create(['chat_id' => (string) self::CHAT_ID, 'name' => 'owner']);

        // A directory of its own on the real disk, because the handler resolves
        // an absolute path and then hashes what is on it.
        config(['receipts.path' => 'test-receipts']);

        $this->directory = Storage::disk((string) config('receipts.disk'))
            ->path((string) config('receipts.path'));

        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0777, true);
        }

        array_map(unlink(...), glob($this->directory.'/*') ?: []);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->directory.'/*') ?: []);

        parent::tearDown();
    }

    public function test_two_uploads_of_the_same_picture_get_the_same_name(): void
    {
        $this->send('file-id-one', 'the same jpeg bytes');
        $this->send('file-id-two', 'the same jpeg bytes');

        $names = [];
        Queue::assertPushed(ProcessReceipt::class, function (ProcessReceipt $job) use (&$names): bool {
            $names[] = $job->filename;

            return true;
        });

        $this->assertCount(2, $names);
        $this->assertSame($names[0], $names[1], 'The same picture should get the same filename.');
        $this->assertMatchesRegularExpression('/^photo_[0-9a-f]{32}\.jpg$/', $names[0]);
    }

    public function test_different_pictures_get_different_names(): void
    {
        $this->send('file-id-one', 'first receipt');
        $this->send('file-id-two', 'second receipt');

        $names = [];
        Queue::assertPushed(ProcessReceipt::class, function (ProcessReceipt $job) use (&$names): bool {
            $names[] = $job->filename;

            return true;
        });

        $this->assertNotSame($names[0], $names[1]);
    }

    public function test_a_picture_that_is_already_saved_is_refused(): void
    {
        $bytes = 'a receipt that was read last week';
        $this->history('photo_'.substr(sha1($bytes), 0, 32).'.jpg');

        $this->send('a-brand-new-file-id', $bytes);

        Telegraph::assertSent('This receipt is already saved.');
        Queue::assertNotPushed(ProcessReceipt::class);
    }

    public function test_a_document_still_keeps_its_own_name(): void
    {
        $this->postJson('/telegraph/'.self::TOKEN.'/webhook', [
            'update_id' => 1,
            'message' => [
                'message_id' => 100,
                'from' => ['id' => self::CHAT_ID, 'is_bot' => false, 'first_name' => 'Owner'],
                'chat' => ['id' => self::CHAT_ID, 'type' => 'private'],
                'date' => now()->timestamp,
                'document' => ['file_id' => 'doc-1', 'file_name' => '2195275_63931899.pdf'],
            ],
        ])->assertNoContent();

        Queue::assertPushed(ProcessReceipt::class, fn (ProcessReceipt $job): bool => $job->filename === '2195275_63931899.pdf');
    }

    private function send(string $fileId, string $bytes): void
    {
        // The handler stores under a name derived from the file id, then hashes
        // whatever landed there. The fake downloads nothing, so put it there.
        file_put_contents(
            $this->directory.'/incoming_'.substr(sha1($fileId), 0, 16).'.jpg',
            $bytes,
        );

        $this->postJson('/telegraph/'.self::TOKEN.'/webhook', [
            'update_id' => 1,
            'message' => [
                'message_id' => 100,
                'from' => ['id' => self::CHAT_ID, 'is_bot' => false, 'first_name' => 'Owner'],
                'chat' => ['id' => self::CHAT_ID, 'type' => 'private'],
                'date' => now()->timestamp,
                'photo' => [
                    ['file_id' => $fileId.'-small', 'width' => 90, 'height' => 160],
                    ['file_id' => $fileId, 'width' => 900, 'height' => 1600],
                ],
            ],
        ])->assertNoContent();
    }

    private function history(string $filename): void
    {
        $shop = Shop::create(['name' => 'Lidl']);
        $product = Product::create([
            'name' => 'Milk',
            'category_id' => Category::create(['name' => 'default'])->id,
            'unit_id' => Unit::create(['name' => 'kpl'])->id,
        ]);

        History::create([
            'product_id' => $product->id,
            'shop_id' => $shop->id,
            'amount' => 1,
            'price' => 1.5,
            'total' => 1.5,
            'date' => '2026-09-11',
            'filename' => $filename,
        ]);
    }
}
