<?php

declare(strict_types=1);

namespace App\Services\SGroup;

use App\Exceptions\ReceiptParseException;
use App\Models\Shop;

/**
 * Maps an S-Etukortti store name to a row in `shops`.
 *
 * The API names the store, not the chain: "Prisma Itäkeskus", "S-market
 * Söderkulla". A shop matches when the store name starts with it, ignoring
 * case — "PRISMA" and "S-Market" both work — and the longest match wins, so a
 * "Prisma Itäkeskus" shop, if one is ever added, beats plain "Prisma".
 *
 * Like the AI parser, this never creates a shop: an unknown chain is an error
 * the user fixes by adding the shop.
 */
class ShopMatcher
{
    public function match(string $storeName): string
    {
        $store = mb_strtolower(trim($storeName));
        $best = null;

        foreach (Shop::pluck('name') as $name) {
            $candidate = mb_strtolower(trim((string) $name));

            if ($candidate === '' || ! str_starts_with($store, $candidate)) {
                continue;
            }

            // The match must end on a word boundary: "Sale" is not "Salemarket".
            $next = mb_substr($store, mb_strlen($candidate), 1);

            if ($next !== '' && preg_match('/[\p{L}\p{N}]/u', $next)) {
                continue;
            }

            if ($best === null || mb_strlen($candidate) > mb_strlen(mb_strtolower($best))) {
                $best = (string) $name;
            }
        }

        if ($best === null) {
            throw ReceiptParseException::unknownShop($storeName);
        }

        return $best;
    }
}
