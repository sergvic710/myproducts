<?php

declare(strict_types=1);

namespace App\DataObjects;

use Carbon\CarbonImmutable;

/**
 * What Wallet spent on groceries, against what the app has receipts for.
 *
 * The three buckets are three different jobs. `missing` is a receipt to go and
 * upload. `unknownShop` is a shop to go and configure — the receipt could not
 * have matched whatever you did. `matched` only exists so the report can say
 * how much of the window was fine.
 */
final readonly class ReconciliationReport
{
    /**
     * @param  list<WalletExpense>  $missing
     * @param  list<WalletExpense>  $unknownShop
     * @param  list<WalletExpense>  $matched
     * @param  list<string>  $ambiguousPayees
     */
    public function __construct(
        public array $missing,
        public array $unknownShop,
        public array $matched,
        public array $ambiguousPayees,
        public CarbonImmutable $since,
        public int $days,
    ) {}

    public function total(): int
    {
        return count($this->missing) + count($this->unknownShop) + count($this->matched);
    }

    public function isClean(): bool
    {
        return $this->missing === [] && $this->unknownShop === [];
    }

    public function missingTotal(): float
    {
        return round(array_sum(array_map(
            static fn (WalletExpense $e): float => $e->amount,
            $this->missing,
        )), 2);
    }

    /**
     * Unknown payees folded per payee rather than listed per expense.
     *
     * Nine separate lines saying "Rimi is not a shop here" is nine times the
     * noise of one line saying it once, and there is only one thing to do
     * about it either way.
     *
     * @return list<array{payee: string, count: int, total: float}>
     */
    public function unknownByPayee(): array
    {
        $byPayee = [];

        foreach ($this->unknownShop as $expense) {
            $key = $expense->label();

            $byPayee[$key] ??= ['payee' => $key, 'count' => 0, 'total' => 0.0];
            $byPayee[$key]['count']++;
            $byPayee[$key]['total'] = round($byPayee[$key]['total'] + $expense->amount, 2);
        }

        usort($byPayee, static fn (array $a, array $b): int => [$b['count'], $a['payee']] <=> [$a['count'], $b['payee']]);

        return array_values($byPayee);
    }
}
