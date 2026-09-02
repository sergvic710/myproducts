<?php

declare(strict_types=1);

namespace App\DataObjects;

/**
 * One line of a receipt, already normalised.
 *
 * `unitPrice` is the price of one unit BEFORE any discount, `price` is the line
 * total AFTER it. When `discountPrice` is above zero the two do not multiply out
 * — the gap is exactly the discount. Summing `price` over a receipt gives the
 * amount actually paid.
 *
 * Keeping them as typed properties removes the old `priceUnit` / `unitPrice`
 * array-key mismatch between the parsers and the repository.
 */
final readonly class ParsedItem
{
    public function __construct(
        public string $name,
        public string $category,
        public string $unit,
        public float $amount,
        public float $unitPrice,
        public float $price,
        public bool $isDiscount = false,
        public float $discountPrice = 0.0,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $amount = (float) ($data['amount'] ?? 1);
        $price = (float) ($data['price'] ?? 0);
        $unitPrice = (float) ($data['unit_price'] ?? 0);

        if ($amount <= 0) {
            $amount = 1.0;
        }

        $discountPrice = (float) ($data['discount_price'] ?? 0);

        // Some receipt lines only show the line total. Derive the unit price from
        // the price before the discount, so unitPrice stays the shelf price.
        // A basket-wide discount is its own line with a negative price and no
        // shelf price of its own, so there the unit price is the amount itself.
        if ($unitPrice <= 0) {
            $unitPrice = $price < 0
                ? round($price / $amount, 2)
                : round(($price + $discountPrice) / $amount, 2);
        }

        return new self(
            name: trim((string) ($data['name'] ?? '')),
            category: trim((string) ($data['category'] ?? '')),
            unit: mb_strtolower(trim((string) ($data['unit'] ?? 'kpl'))),
            amount: $amount,
            unitPrice: $unitPrice,
            price: $price,
            isDiscount: (bool) ($data['is_discount'] ?? false) || $discountPrice > 0,
            discountPrice: $discountPrice,
        );
    }
}
