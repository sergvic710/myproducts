<?php

declare(strict_types=1);

namespace App\DataObjects;

use App\Exceptions\ReceiptParseException;
use Carbon\CarbonImmutable;

final readonly class ParsedReceipt
{
    /**
     * @param  ParsedItem[]  $items
     */
    public function __construct(
        public string $shop,
        public CarbonImmutable $date,
        public float $total,
        public array $items,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $items = [];

        foreach ($data['products'] ?? [] as $product) {
            if (! is_array($product)) {
                continue;
            }

            $item = ParsedItem::fromArray($product);

            if ($item->name !== '') {
                $items[] = $item;
            }
        }

        return new self(
            shop: trim((string) ($data['shop'] ?? '')),
            date: self::parseDate($data['date'] ?? null),
            total: (float) ($data['total'] ?? 0),
            items: $items,
        );
    }

    public function itemCount(): int
    {
        return count($this->items);
    }

    /**
     * Sum of the line totals. Use it to sanity-check against `total`.
     */
    public function sum(): float
    {
        return round(array_sum(array_map(fn (ParsedItem $item): float => $item->price, $this->items)), 2);
    }

    private static function parseDate(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            throw new ReceiptParseException('The receipt has no readable date.');
        }

        try {
            return CarbonImmutable::parse(trim($value))->startOfDay();
        } catch (\Throwable $e) {
            throw new ReceiptParseException("Could not read the receipt date \"{$value}\".", 0, $e);
        }
    }
}
