<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a job's progress back to the chat the receipt came from.
 *
 * Receipts also arrive from the Google Drive sync, which has no chat, so the
 * id is optional throughout. A reply that cannot be sent is never allowed to
 * fail the job — by the time these run, the data is already saved.
 *
 * Expects the using job to have a `?int $telegraphChatId` property.
 */
trait NotifiesTelegram
{
    protected function notify(string $text): void
    {
        if ($this->telegraphChatId === null) {
            return;
        }

        $chat = TelegraphChat::find($this->telegraphChatId);

        if (! $chat) {
            return;
        }

        try {
            $chat->message($text)->send();
        } catch (Throwable $e) {
            Log::warning('Could not send the Telegram reply.', ['error' => $e->getMessage()]);
        }
    }
}
