<?php

declare(strict_types=1);

namespace Tests\Feature\SGroup;

use App\Models\SGroupSession;
use DefStudio\Telegraph\Facades\Telegraph;
use DefStudio\Telegraph\Models\TelegraphBot;
use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SGroupBotCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token';

    private const CHAT_ID = 424242;

    private TelegraphChat $chat;

    protected function setUp(): void
    {
        parent::setUp();

        Telegraph::fake();
        Queue::fake();

        $bot = TelegraphBot::create(['token' => self::TOKEN, 'name' => 'test']);
        $this->chat = $bot->chats()->create(['chat_id' => (string) self::CHAT_ID, 'name' => 'owner']);
    }

    public function test_a_session_is_stored_for_the_chat_and_the_message_deleted(): void
    {
        Http::fake(['*' => Http::response(['data' => ['receipts' => []], 'errors' => null, 'status' => 'OK'])]);

        $this->command('/sgroup sid-value xsrf-value');

        $session = SGroupSession::sole();
        $this->assertSame('sid-value', $session->session_id);
        $this->assertSame('xsrf-value', $session->xsrf_token);
        $this->assertSame($this->chat->id, $session->telegraph_chat_id);

        // The message carried a login; it must not stay in the chat history.
        Telegraph::assertSentData(\DefStudio\Telegraph\Telegraph::ENDPOINT_DELETE_MESSAGE, ['message_id' => 100], exact: false);

        Telegraph::assertSent('Session saved. No new S-ryhmä receipts — I will keep checking every 15 minutes.');
    }

    public function test_a_session_the_api_refuses_is_reported_at_once(): void
    {
        Http::fake(['*' => Http::response('', 401)]);

        $this->command('/sgroup stale-sid stale-xsrf');

        $this->assertNotNull(SGroupSession::sole()->expired_at);
        Telegraph::assertSent('S-Etukortti did not accept that session.', exact: false);
    }

    public function test_without_arguments_it_reports_the_status(): void
    {
        $this->command('/sgroup');

        Telegraph::assertSent('S-Etukortti sync is not set up.', exact: false);
        $this->assertSame(0, SGroupSession::count());
    }

    public function test_one_value_is_not_enough(): void
    {
        $this->command('/sgroup only-one');

        $this->assertSame(0, SGroupSession::count());
    }

    private function command(string $text): void
    {
        $this->postJson('/telegraph/'.self::TOKEN.'/webhook', [
            'update_id' => random_int(1, 1_000_000),
            'message' => [
                'message_id' => 100,
                'from' => ['id' => self::CHAT_ID, 'is_bot' => false, 'first_name' => 'Owner'],
                'chat' => ['id' => self::CHAT_ID, 'type' => 'private'],
                'date' => now()->timestamp,
                'text' => $text,
            ],
        ])->assertNoContent();
    }
}
