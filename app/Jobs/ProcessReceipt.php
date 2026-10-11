<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\NotifiesTelegram;
use App\Models\History;
use App\Repositories\ProductRepository;
use App\Services\Parser\AiReceiptParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Throwable;

/**
 * Reads one receipt file with Claude and writes it to the database.
 *
 * This runs on the queue, not inside the Telegram webhook: parsing takes far
 * longer than the ~60s after which Telegram retries the webhook.
 */
class ProcessReceipt implements ShouldQueue
{
    use NotifiesTelegram;
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public int $timeout = 300;

    public function __construct(
        public readonly string $filePath,
        public readonly string $filename,
        public readonly ?int $telegraphChatId = null,
    ) {}

    public function handle(AiReceiptParser $parser, ProductRepository $repository): void
    {
        // Telegram retries webhooks, and Google Drive sync can see the same file
        // twice. Saving a receipt is idempotent, but skip the API call as well.
        if (History::where('filename', $this->filename)->exists()) {
            Log::info('Receipt already saved, skipping.', ['filename' => $this->filename]);

            return;
        }

        try {
            $receipt = $parser->parse($this->filePath);
        } catch (PrismRateLimitedException $e) {
            $this->release($e->retryAfter ?? 60);

            return;
        }

        $saved = $repository->saveReceipt($receipt, $this->filename);

        Log::info('Receipt saved.', [
            'filename' => $this->filename,
            'shop' => $receipt->shop,
            'items' => $saved,
            'total' => $receipt->total,
        ]);

        // A separate job, so a Wallet outage can never touch a saved receipt.
        PushReceiptToWallet::dispatch(
            $this->filename,
            $receipt->shop,
            $receipt->date->toDateString(),
            $receipt->total,
            $saved,
            $this->telegraphChatId,
        );

        $this->notify(sprintf(
            'Saved %d items — %.2f EUR (%s, %s)',
            $saved,
            $receipt->total,
            $receipt->shop,
            $receipt->date->toDateString(),
        ));
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Receipt processing failed.', [
            'filename' => $this->filename,
            'error' => $e?->getMessage(),
        ]);

        $this->notify('Could not read this receipt: '.($e?->getMessage() ?? 'unknown error'));
    }
}
