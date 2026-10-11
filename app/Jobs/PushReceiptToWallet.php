<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\WalletException;
use App\Jobs\Concerns\NotifiesTelegram;
use App\Models\Shop;
use App\Models\WalletRecord;
use App\Services\Wallet\BudgetBakersClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates one expense transaction in BudgetBakers Wallet for a saved receipt.
 *
 * This is a job of its own rather than a step inside ProcessReceipt: the
 * receipt is already committed by the time it runs, and a Wallet outage, an
 * expired token or a shop with no template must never fail or roll back a
 * receipt that was read correctly.
 *
 * Wallet has no template API, so the shop row stands in for the template named
 * after the shop — see Shop::hasWalletTemplate().
 */
class PushReceiptToWallet implements ShouldQueue
{
    use NotifiesTelegram;
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public int $timeout = 60;

    public function __construct(
        public readonly string $filename,
        public readonly string $shopName,
        public readonly string $date,
        public readonly float $total,
        public readonly int $itemCount,
        public readonly ?int $telegraphChatId = null,
    ) {}

    public function handle(BudgetBakersClient $client): void
    {
        if (! config('budgetbakers.enabled') || ! $client->isConfigured()) {
            return;
        }

        $shop = Shop::whereRaw('LOWER(name) = ?', [mb_strtolower($this->shopName)])->first();

        // Claiming the row before the API call is what makes a retry safe: a
        // receipt that already reached Wallet is settled and never sent twice.
        $record = WalletRecord::firstOrCreate(
            ['filename' => $this->filename],
            [
                'shop_id' => $shop?->id,
                'amount' => round($this->total, 2),
                'record_date' => $this->date,
                'status' => WalletRecord::PENDING,
            ]
        );

        if ($record->isSettled()) {
            Log::info('Receipt already handled in Wallet, skipping.', [
                'filename' => $this->filename,
                'status' => $record->status,
            ]);

            return;
        }

        if (! $shop || ! $shop->hasWalletTemplate()) {
            $this->skip($record, sprintf(
                'No Wallet template for "%s" — set its account under Shops.',
                $this->shopName
            ));

            return;
        }

        $accountId = $client->findAccountId($shop->wallet_account);

        if ($accountId === null) {
            $this->skip($record, sprintf(
                'No Wallet account named "%s" (set on shop "%s").',
                $shop->wallet_account,
                $shop->name
            ));

            return;
        }

        $categoryId = null;

        if (filled($shop->wallet_category)) {
            $categoryId = $client->findCategoryId($shop->wallet_category);

            if ($categoryId === null) {
                $this->skip($record, sprintf(
                    'No Wallet category named "%s" (set on shop "%s").',
                    $shop->wallet_category,
                    $shop->name
                ));

                return;
            }
        }

        try {
            $walletId = $client->createRecord($this->record($shop, $accountId, $categoryId));
        } catch (WalletException $e) {
            // A rate limit never reached the write, so releasing the job cannot
            // duplicate the transaction. Every other failure retries under
            // $tries, which is a deliberate trade: losing a transaction is far
            // more likely than a request that failed on our side but landed on
            // theirs.
            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);

                return;
            }

            throw $e;
        }

        $record->update([
            'status' => WalletRecord::CREATED,
            'wallet_record_id' => $walletId,
            'error' => null,
        ]);

        Log::info('Receipt added to Wallet.', [
            'filename' => $this->filename,
            'shop' => $shop->name,
            'wallet_record_id' => $walletId,
        ]);

        $this->notify(sprintf('Added to Wallet: %.2f EUR (%s).', -abs($this->total), $shop->name));
    }

    public function failed(?Throwable $e): void
    {
        $message = $e?->getMessage() ?? 'unknown error';

        WalletRecord::where('filename', $this->filename)->update([
            'status' => WalletRecord::FAILED,
            'error' => $message,
        ]);

        Log::error('Could not add the receipt to Wallet.', [
            'filename' => $this->filename,
            'error' => $message,
        ]);

        $this->notify('Saved, but not added to Wallet: '.$message);
    }

    /**
     * The transaction as Wallet wants it.
     *
     * `currencyCode` is left out on purpose — Wallet then uses the account's
     * own currency, and rejects the record outright if a currency is given
     * that does not match it.
     *
     * @return array<string, mixed>
     */
    private function record(Shop $shop, string $accountId, ?string $categoryId): array
    {
        $record = [
            'accountId' => $accountId,
            'amount' => ['value' => -abs(round($this->total, 2))],
            'recordDate' => CarbonImmutable::parse($this->date)->startOfDay()->toIso8601String(),
            'counterParty' => mb_substr($shop->wallet_payee ?: $shop->name, 0, 255),
            'note' => mb_substr(sprintf('%d items — %s', $this->itemCount, $this->filename), 0, 255),
        ];

        if ($categoryId !== null) {
            $record['categoryId'] = $categoryId;
        }

        return $record;
    }

    /**
     * A missing or misspelled template is a settings gap, not a fault: no
     * number of retries will fix it, so say so once and stop.
     */
    private function skip(WalletRecord $record, string $reason): void
    {
        $record->update([
            'status' => WalletRecord::SKIPPED,
            'error' => $reason,
        ]);

        Log::warning('Receipt not added to Wallet.', [
            'filename' => $this->filename,
            'reason' => $reason,
        ]);

        $this->notify($reason);
    }
}
