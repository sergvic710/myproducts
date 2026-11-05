<?php

declare(strict_types=1);

namespace App\Services\SGroup;

/**
 * Reads the line items out of a printed S-ryhmä receipt (`receiptData`).
 *
 * The text is the receipt exactly as the till printed it, so there is nothing
 * to OCR — the layout is fixed and a few patterns cover it:
 *
 *     ------------------------------------------      <- items start
 *     *COOP MANGORAHKA 150G                 1,10 *     <- name, line total
 *       2 KPL             0,55 €/KPL                   <- optional: quantity, unit price
 *     *BANAANI CHIQUITA                     1,43 *
 *       0,761 KG          1,88 €/KG
 *     ------------------------------------------      <- items end
 *     *YHTEENSÄ                            27,95 *
 *
 * It only claims a receipt it fully understands: any line it cannot place, and
 * any negative line (a discount, whose owner is a judgement call), makes the
 * result `certain = false`, and the caller hands the receipt to the LLM instead.
 * A receipt whose lines do not add up to the total is never certain either.
 */
class ReceiptTextParser
{
    private const SEPARATOR = '/^\s*-{10,}\s*$/u';

    // "*NAME   1,10 *" — the name may itself contain spaces, digits and %.
    private const ITEM = '/^\s*\*(?<name>.+?)\s{2,}(?<price>-?\d+,\d{2})-?\s*\*\s*$/u';

    // "  2 KPL   0,55 €/KPL" or "  0,761 KG   1,88 €/KG"
    private const QUANTITY = '/^\s+(?<amount>\d+(?:,\d+)?)\s+(?<unit>KPL|KG|L)\s+(?<unit_price>\d+,\d{2})\s*(?:€|EUR)\s*\/\s*\w+\s*$/iu';

    private const TOTAL = '/^\s*\*?\s*YHTEENSÄ\s+(?<total>-?\d+,\d{2})\s*\*?\s*$/u';

    public function parse(string $text): ReceiptTextResult
    {
        $lines = preg_split('/\R/u', $text) ?: [];

        $section = 'header';
        $items = [];
        $total = null;
        $certain = true;

        foreach ($lines as $line) {
            if (preg_match(self::SEPARATOR, $line)) {
                // header -> items -> footer; a third separator changes nothing.
                $section = $section === 'header' ? 'items' : 'footer';

                continue;
            }

            if ($section === 'items') {
                if (preg_match(self::ITEM, $line, $m)) {
                    $price = self::number($m['price']);

                    if ($price < 0) {
                        $certain = false;
                    }

                    $items[] = [
                        'name' => trim((string) preg_replace('/\s+/u', ' ', $m['name'])),
                        'unit' => 'kpl',
                        'amount' => 1.0,
                        'unit_price' => $price,
                        'price' => $price,
                    ];
                } elseif ($items !== [] && preg_match(self::QUANTITY, $line, $m)) {
                    $last = array_key_last($items);
                    $items[$last]['amount'] = self::number($m['amount']);
                    $items[$last]['unit'] = mb_strtolower($m['unit']);
                    $items[$last]['unit_price'] = self::number($m['unit_price']);
                } elseif (trim($line) !== '') {
                    $certain = false;
                }

                continue;
            }

            if ($section === 'footer' && $total === null && preg_match(self::TOTAL, $line, $m)) {
                $total = self::number($m['total']);
            }
        }

        $sum = round(array_sum(array_column($items, 'price')), 2);

        if ($items === [] || $total === null || abs($sum - $total) > 0.001) {
            $certain = false;
        }

        return new ReceiptTextResult($items, $total, $certain);
    }

    private static function number(string $value): float
    {
        return (float) str_replace(',', '.', $value);
    }
}
