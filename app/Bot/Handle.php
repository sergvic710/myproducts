<?php

declare(strict_types=1);

namespace App\Bot;

use App\Jobs\ProcessReceipt;
use App\Models\History;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Stringable;

class Handle extends WebhookHandler
{
    public function start(): void
    {
        $this->reply('Hello. Send me a receipt as a PDF file or as a photo.');
    }

    protected function handleUnknownCommand(Stringable $message): void
    {
        $this->reply('Unknown command. Send me a receipt as a PDF file or as a photo.');
    }

    /**
     * Save the attached receipt and hand it to the queue.
     *
     * Nothing is parsed here: reading a receipt takes longer than the ~60s
     * after which Telegram retries the webhook.
     */
    protected function handleChatMessage(Stringable $message): void
    {
        $document = $this->message?->document();
        // photos() holds the same picture in several sizes, largest last.
        $photo = $this->message?->photos()->last();
        $attachment = $document ?? $photo;

        if ($attachment === null) {
            $this->chat->message('Send me a receipt: a PDF file or a photo.')->send();

            return;
        }

        $filename = $this->filename($document?->filename(), $attachment->id());

        if (History::where('filename', $filename)->exists()) {
            $this->chat->message('This receipt is already saved.')->send();

            return;
        }

        $disk = Storage::disk((string) config('receipts.disk'));
        $directory = $disk->path((string) config('receipts.path'));

        $storedPath = $this->bot->store($attachment, $directory, $filename);

        $this->chat->message('Receipt received. Reading it now…')->send();

        ProcessReceipt::dispatch($storedPath, $filename, $this->chat->id);
    }

    /**
     * Documents keep their own name. Photos have none, so derive a stable name
     * from the Telegram file id — the same photo then always maps to the same
     * filename, which is what makes the pipeline idempotent.
     */
    private function filename(?string $documentName, string $fileId): string
    {
        $documentName = $documentName !== null ? trim($documentName) : '';

        if ($documentName !== '') {
            return substr(basename($documentName), -255);
        }

        return 'photo_'.substr(sha1($fileId), 0, 16).'.jpg';
    }
}
