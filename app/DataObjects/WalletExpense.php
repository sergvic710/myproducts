<?php

declare(strict_types=1);

namespace App\DataObjects;

/**
 * One Groceries expense as Wallet has it, already matched to a shop if it could be.
 *
 * `amount` is positive here while Wallet stores expenses negative — the report
 * says "missing 24.90", and a minus sign in front of that reads as a refund.
 *
 * `shopId` is null when the payee matched no shop at all, which is a different
 * problem from a missing receipt: it means the shop was never set up, so the
 * receipt could not have been saved against it even if it existed.
 */
final readonly class WalletExpense
{
    public function __construct(
        public string $walletId,
        public string $date,
        public string $payee,
        public float $amount,
        public ?int $shopId = null,
        public ?string $shopName = null,
    ) {}

    public function hasShop(): bool
    {
        return $this->shopId !== null;
    }

    public function label(): string
    {
        return $this->shopName ?? ($this->payee !== '' ? $this->payee : '(no payee)');
    }

    public function withShop(?int $shopId, ?string $shopName): self
    {
        return new self(
            walletId: $this->walletId,
            date: $this->date,
            payee: $this->payee,
            amount: $this->amount,
            shopId: $shopId,
            shopName: $shopName,
        );
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public static function fromArray(array $record): self
    {
        // recordDate comes back at noon UTC rather than midnight, which is
        // Wallet being careful: the date part is then the same day in every
        // timezone anyone is likely to be in.
        $date = substr((string) ($record['recordDate'] ?? ''), 0, 10);

        $amount = $record['amount']['value'] ?? 0;

        return new self(
            walletId: (string) ($record['id'] ?? ''),
            date: $date,
            payee: trim((string) ($record['counterParty'] ?? '')),
            amount: abs((float) (is_numeric($amount) ? $amount : 0)),
        );
    }
}
