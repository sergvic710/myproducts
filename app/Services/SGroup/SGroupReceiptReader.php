<?php

declare(strict_types=1);

namespace App\Services\SGroup;

use App\DataObjects\ParsedItem;
use App\DataObjects\ParsedReceipt;
use App\Exceptions\ReceiptParseException;
use App\Services\Parser\AiReceiptParser;
use Carbon\CarbonImmutable;

/**
 * Turns one S-Etukortti receipt into the same ParsedReceipt a PDF or photo
 * becomes, so everything downstream — ProductRepository, Wallet, analytics,
 * /shoplist — handles it without knowing where it came from.
 *
 * The header fields (store, time, total) come from the API and are trusted as
 * they are. The line items come from ReceiptTextParser when it is certain, and
 * otherwise from the LLM reading the same text.
 */
class SGroupReceiptReader
{
    public function __construct(
        private readonly ReceiptTextParser $textParser,
        private readonly ShopMatcher $shops,
        private readonly ProductCategorizer $categorizer,
        private readonly AiReceiptParser $ai,
    ) {}

    /**
     * @param  array<string, mixed>  $receipt  one receipt as SEtukorttiClient::receipt() returns it
     */
    public function read(array $receipt): ParsedReceipt
    {
        $id = (string) ($receipt['receiptId'] ?? '');
        $text = (string) ($receipt['receiptData'] ?? '');

        if ($text === '') {
            throw new ReceiptParseException("S-Etukortti receipt {$id} has no receipt text.");
        }

        $shop = $this->shops->match((string) ($receipt['placeOfBusinessName'] ?? ''));

        // The API gives the time with its offset (+03:00 in summer, +02:00 in
        // winter). startOfDay keeps that offset, so a purchase at 00:30 local
        // time stays on its own day instead of sliding into the previous UTC one.
        $date = CarbonImmutable::parse((string) ($receipt['transactionStart'] ?? ''))->startOfDay();
        $total = round((float) ($receipt['totalAmount'] ?? 0), 2);

        $lines = $this->textParser->parse($text);

        if (! $lines->certain) {
            $ai = $this->ai->parseText($text, "S-Etukortti receipt {$id}");

            // The model reads the lines; the shop, date and total stay the API's.
            return new ParsedReceipt($shop, $date, $total, $ai->items);
        }

        $categories = $this->categorizer->categorize(array_column($lines->items, 'name'));

        $items = array_map(fn (array $line): ParsedItem => new ParsedItem(
            name: $line['name'],
            category: $categories[$line['name']] ?? (string) config('receipts.fallback_category'),
            unit: $line['unit'],
            amount: $line['amount'],
            unitPrice: $line['unit_price'],
            price: $line['price'],
        ), $lines->items);

        return new ParsedReceipt($shop, $date, $total, $items);
    }
}
