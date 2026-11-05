<?php

declare(strict_types=1);

namespace App\Services\SGroup;

/**
 * What ReceiptTextParser read. `certain` is false whenever anything on the
 * receipt was not understood; the items are then not to be trusted.
 */
final readonly class ReceiptTextResult
{
    /**
     * @param  array<int, array{name: string, unit: string, amount: float, unit_price: float, price: float}>  $items
     */
    public function __construct(
        public array $items,
        public ?float $total,
        public bool $certain,
    ) {}
}
