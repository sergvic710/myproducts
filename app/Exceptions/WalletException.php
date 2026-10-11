<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Something went wrong talking to BudgetBakers Wallet.
 *
 * A rate limit is the one case the caller reacts to rather than fails on, so
 * it carries the server's Retry-After value.
 */
class WalletException extends RuntimeException
{
    public ?int $retryAfter = null;

    public static function notConfigured(): self
    {
        return new self('BUDGETBAKERS_TOKEN is not set.');
    }

    public static function rateLimited(?int $seconds): self
    {
        $exception = new self(sprintf(
            'BudgetBakers rate limit reached, retrying in %d s.',
            $seconds ?? 60
        ));

        $exception->retryAfter = $seconds ?? 60;

        return $exception;
    }

    public static function unknownAccount(string $name): self
    {
        return new self("No Wallet account named \"{$name}\".");
    }

    public static function unknownCategory(string $name): self
    {
        return new self("No Wallet category named \"{$name}\".");
    }

    /**
     * Wallet accepted the request but rejected this record. Per-item errors
     * arrive in `results[]`, so a rejected single record can come back as a
     * 200 or a 207.
     */
    public static function rejected(string $message): self
    {
        return new self("Wallet rejected the transaction: {$message}");
    }

    public static function httpError(int $status, string $body): self
    {
        return new self(sprintf(
            'BudgetBakers returned HTTP %d: %s',
            $status,
            mb_substr(trim($body), 0, 300)
        ));
    }
}
