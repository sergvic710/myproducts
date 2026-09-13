<?php

declare(strict_types=1);

namespace App\DataObjects;

/**
 * What to buy, split by urgency.
 *
 * `candidateCount` is the number of products with enough history to predict at
 * all. It is what separates "you have not bought anything often enough yet"
 * from "nothing is due today" — two very different things to tell someone, and
 * an empty list alone cannot tell them apart.
 */
final readonly class ShoppingList
{
    /**
     * @param  list<DueProduct>  $overdue
     * @param  list<DueProduct>  $dueSoon
     */
    public function __construct(
        public array $overdue,
        public array $dueSoon,
        public int $candidateCount,
        public int $snoozedCount,
    ) {}

    /**
     * @return list<DueProduct> overdue first, then due soon
     */
    public function all(): array
    {
        return array_merge($this->overdue, $this->dueSoon);
    }

    public function isEmpty(): bool
    {
        return $this->overdue === [] && $this->dueSoon === [];
    }

    public function count(): int
    {
        return count($this->overdue) + count($this->dueSoon);
    }
}
