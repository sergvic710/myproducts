<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesBot;
use DefStudio\Telegraph\Models\TelegraphBot;
use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sends a test message, to check that the bot and the chat are wired up.
 */
class BotCommand extends Command
{
    use ResolvesBot;

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
}
