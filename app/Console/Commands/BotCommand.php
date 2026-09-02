<?php

declare(strict_types=1);

namespace App\Console\Commands;

use DefStudio\Telegraph\Models\TelegraphBot;
use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sends a test message, to check that the bot and the chat are wired up.
 *
 * The bot is looked up, not guessed: the old version used TelegraphBot::fromId(1)
 * and broke as soon as the row id was anything but 1.
 */
class BotCommand extends Command
{
    protected $signature = 'bot
                            {message=Hello : Text to send}
                            {--bot= : Bot id, when more than one bot is registered}
                            {--chat= : Chat id, defaults to the first chat of the bot}';

    protected $description = 'Send a test message from the Telegram bot';

    public function handle(): int
    {
        $bot = $this->bot();

        if (! $bot) {
            return self::FAILURE;
        }

        $chat = $this->chat($bot);

        if (! $chat) {
            return self::FAILURE;
        }

        $message = (string) $this->argument('message');

        try {
            $chat->message($message)->send();
        } catch (Throwable $e) {
            $this->error('Telegram refused the message: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Sent \"{$message}\" to {$chat->name} (chat {$chat->chat_id}) via bot {$bot->name}.");

        return self::SUCCESS;
    }

    private function bot(): ?TelegraphBot
    {
        if ($id = $this->option('bot')) {
            $bot = TelegraphBot::find($id);

            if (! $bot) {
                $this->error("There is no bot with id {$id}.");
                $this->listBots();
            }

            return $bot;
        }

        $bots = TelegraphBot::all();

        if ($bots->isEmpty()) {
            $this->error('No bot is registered. Run: php artisan telegraph:new-bot');

            return null;
        }

        if ($bots->count() > 1) {
            $this->error('There is more than one bot. Choose one with --bot=<id>.');
            $this->listBots();

            return null;
        }

        return $bots->first();
    }

    private function chat(TelegraphBot $bot): ?TelegraphChat
    {
        $chat = $this->option('chat')
            ? $bot->chats()->where('chat_id', $this->option('chat'))->first()
            : $bot->chats()->first();

        if (! $chat) {
            $this->error("Bot \"{$bot->name}\" has no matching chat. Run: php artisan telegraph:new-chat");
        }

        return $chat;
    }

    private function listBots(): void
    {
        $this->table(
            ['id', 'name', 'chats'],
            TelegraphBot::withCount('chats')->get()
                ->map(fn (TelegraphBot $b): array => [$b->id, $b->name, $b->chats_count])
                ->all()
        );
    }
}
