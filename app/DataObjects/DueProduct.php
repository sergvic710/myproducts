<?php

declare(strict_types=1);

namespace App\DataObjects;

use Carbon\CarbonImmutable;

/**
 * One product the purchase history says is running out.
 *
 * `intervalDays` is the median of the recent gaps between purchases, not the
 * mean: a product bought weekly for a year and then forgotten for eight months
 * has a mean gap that describes neither habit, while the median still says
 * "weekly". `ratio` is how far through that cycle we are — 1.0 is the due date,
 * anything above it is overdue.
 */
final readonly class DueProduct
{
    public function __construct(
        public int $productId,
        public string $name,
        public int $intervalDays,
        public int $daysSince,
        public CarbonImmutable $lastPurchase,
        public CarbonImmutable $dueDate,
        public float $ratio,
    ) {}

    public function isOverdue(): bool
    {
        return $this->ratio >= 1.0;
    }

    public function daysOverdue(): int
    {
        return max(0, $this->daysSince - $this->intervalDays);
    }

    public function daysUntilDue(): int
    {
        return max(0, $this->intervalDays - $this->daysSince);
    }
}
