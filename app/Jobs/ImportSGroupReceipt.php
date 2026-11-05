<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\SGroupSessionExpiredException;
use App\Jobs\Concerns\NotifiesTelegram;
use App\Models\History;
use App\Models\SGroupSession;
use App\Repositories\ProductRepository;
use App\Services\SGroup\SEtukorttiClient;
use App\Services\SGroup\SGroupReceiptReader;
use App\Services\SGroup\SGroupSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Throwable;

/**
 * Fetches one S-Etukortti receipt, reads it and saves it — the S-ryhmä
 * counterpart of ProcessReceipt, ending in the same repository and the same
 * Wallet push. The filename is "s-etukortti:<receiptId>", so every existing
 * dedupe check applies unchanged.
 */
class ImportSGroupReceipt implements ShouldQueue
{
    use NotifiesTelegram;
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public int $timeout = 300;

    public function __construct(
        public readonly string $receiptId,
        public readonly ?int $telegraphChatId = null,
    ) {}

    public static function filename(string $receiptId): string
    {
        return config('sgroup.filename_prefix').$receiptId;
    }

    /**
     * Saved by this sync, or already uploaded by hand: the PDF receipts S-ryhmä
     * hands out are named after the same id ("2195275_63931899.pdf"), so one
     * purchase would otherwise be saved twice.
     */
    public static function alreadySaved(string $receiptId): bool
    {
        // Exact names, not LIKE: the id contains "_", a LIKE wildcard, and
        // escaping it works differently on MariaDB and sqlite.
        return History::whereIn('filename', [self::filename($receiptId), $receiptId.'.pdf', $receiptId.'.PDF'])
            ->exists();
    }

    public function handle(SGroupReceiptReader $reader, ProductRepository $repository, SGroupSync $sync): void
    {
        $filename = self::filename($this->receiptId);

        if (self::alreadySaved($this->receiptId)) {
            return;
        }

        $session = SGroupSession::active();

        if (! $session) {
            // Expired between the sync and this job; the next sync after a new
            // /sgroup lists this receipt again.
            return;
        }

        try {
            $receipt = $reader->read((new SEtukorttiClient($session))->receipt($this->receiptId));
        } catch (SGroupSessionExpiredException $e) {
            $sync->expire($session);

            return;
        } catch (PrismRateLimitedException $e) {
            $this->release($e->retryAfter ?? 60);

            return;
        }

        $saved = $repository->saveReceipt($receipt, $filename);

        Log::info('S-Etukortti receipt saved.', [
            'filename' => $filename,
            'shop' => $receipt->shop,
            'items' => $saved,
            'total' => $receipt->total,
        ]);

        PushReceiptToWallet::dispatch(
            $filename,
            $receipt->shop,
            $receipt->date->toDateString(),
            $receipt->total,
            $saved,
            $this->telegraphChatId,
        );

        $this->notify(sprintf(
            'S-Etukortti: saved %d items — %.2f EUR (%s, %s)',
            $saved,
            $receipt->total,
            $receipt->shop,
            $receipt->date->toDateString(),
        ));
    }

    public function failed(?Throwable $e): void
    {
        Log::error('S-Etukortti receipt import failed.', [
            'receipt' => $this->receiptId,
            'error' => $e?->getMessage(),
        ]);

        $this->notify("Could not import S-Etukortti receipt {$this->receiptId}: ".($e?->getMessage() ?? 'unknown error'));
    }
}
