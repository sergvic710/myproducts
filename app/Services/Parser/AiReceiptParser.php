<?php

declare(strict_types=1);

namespace App\Services\Parser;

use App\DataObjects\ParsedReceipt;
use App\Exceptions\ReceiptParseException;
use App\Models\Category;
use App\Models\Shop;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Media;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Reads a receipt with an LLM and returns a normalised ParsedReceipt.
 *
 * The model accepts PDF and images directly, so one parser covers every shop
 * and both input types. Shop and category names are passed to the model as
 * enums built from the database, so the model can only pick values that
 * already exist. It cannot invent a new shop or a new category.
 *
 * Provider and model come from config/receipts.php, so switching between
 * Anthropic, Gemini and Mistral is an .env change, not a code change.
 */
class AiReceiptParser
{
    /** Units we accept. Anything else is mapped to `kpl` by the model. */
    private const UNITS = ['kpl', 'kg', 'l'];

    public function parse(string $filePath): ParsedReceipt
    {
        $media = $this->mediaFor($filePath);

        $shops = Shop::orderBy('name')->pluck('name')->all();
        $categories = Category::orderBy('name')->pluck('name')->all();

        if ($shops === []) {
            throw new ReceiptParseException('The shops table is empty. Add at least one shop first.');
        }

        if ($categories === []) {
            throw new ReceiptParseException('The categories table is empty. Add at least one category first.');
        }

        $provider = $this->provider();

        $request = Prism::structured()
            ->using($provider, (string) config('receipts.model'))
            ->withSchema($this->schema($shops, $categories))
            ->withSystemPrompt($this->systemPrompt())
            ->withMessages([new UserMessage($this->instruction($categories), [$media])])
            ->withMaxTokens(16000);

        if ($provider === Provider::Anthropic) {
            // Anthropic has no native JSON mode. Tool calling is more reliable
            // than asking the model for raw JSON text.
            $request->withProviderOptions(['use_tool_calling' => true]);
        }

        $response = $request->asStructured();

        if (empty($response->structured)) {
            throw ReceiptParseException::emptyResponse($filePath);
        }

        return ParsedReceipt::fromArray($response->structured);
    }

    private function provider(): Provider
    {
        $name = (string) config('receipts.provider');
        $provider = Provider::tryFrom($name);

        if ($provider === null) {
            throw new ReceiptParseException("Unknown receipt provider \"{$name}\". Check config/receipts.php.");
        }

        return $provider;
    }

    /**
     * Turn the file into the right Prism media object.
     */
    private function mediaFor(string $filePath): Media
    {
        if (! is_file($filePath)) {
            throw ReceiptParseException::fileMissing($filePath);
        }

        $size = (int) filesize($filePath);
        $limit = (int) config('receipts.max_file_size');

        if ($size > $limit) {
            throw ReceiptParseException::fileTooLarge($filePath, $size, $limit);
        }

        $mimeType = mime_content_type($filePath) ?: null;

        if ($mimeType === 'application/pdf') {
            return Document::fromLocalPath($filePath, basename($filePath));
        }

        if ($mimeType !== null && str_starts_with($mimeType, 'image/')) {
            return Image::fromLocalPath($filePath);
        }

        throw ReceiptParseException::unsupportedType($filePath, $mimeType);
    }

    /**
     * @param  array<int, string>  $shops
     * @param  array<int, string>  $categories
     */
    private function schema(array $shops, array $categories): ObjectSchema
    {
        $product = new ObjectSchema(
            name: 'product',
            description: 'One line item of the receipt.',
            properties: [
                new StringSchema('name', 'Product name exactly as printed on the receipt.'),
                new EnumSchema('category', 'Category of the product. Pick the closest one from the list.', $categories),
                new EnumSchema('unit', 'Unit of measure: kpl for pieces, kg for weight, l for volume.', self::UNITS),
                new NumberSchema('amount', 'How many units were bought. Pieces (2) or weight in kg (0.857). Use 1 if the receipt does not say.'),
                new NumberSchema('unit_price', 'Price of one unit in EUR, BEFORE any discount.'),
                new NumberSchema('price', 'Line total in EUR, AFTER the discount that belongs to this line. Negative for a basket-wide discount line.'),
                new BooleanSchema('is_discount', 'True when this line had a discount, or when the line itself is a basket-wide discount.'),
                new NumberSchema('discount_price', 'How much was saved on this line, as a positive number. 0 when there is no discount.'),
            ],
            requiredFields: ['name', 'category', 'unit', 'amount', 'unit_price', 'price', 'is_discount', 'discount_price'],
        );

        return new ObjectSchema(
            name: 'receipt',
            description: 'A grocery receipt.',
            properties: [
                new EnumSchema('shop', 'The store this receipt is from. Pick from the list.', $shops),
                new StringSchema('date', 'Purchase date in YYYY-MM-DD format.'),
                new NumberSchema('total', 'Grand total of the receipt in EUR (the YHTEENSA line).'),
                new ArraySchema('products', 'Every product line of the receipt.', $product),
            ],
            requiredFields: ['shop', 'date', 'total', 'products'],
        );
    }

    private function systemPrompt(): string
    {
        return <<<'TEXT'
        You read grocery receipts from Finnish stores (Prisma, Lidl, S-market and similar)
        and return them as structured data. The receipt can be a PDF or a photo.

        Rules for reading:

        - Numbers use a comma as the decimal separator. "25,32" means 25.32.
        - The shop name is printed in the header, for example "PRISMA ITAKESKUS" or "LIDL".
          Match it to one of the allowed shop names.
        - The date can be ANYWHERE on the receipt, not only in the header. Some receipts
          print it only in the card payment block below the total. Search the whole
          receipt for it. Formats vary: "21.9.2025", "24/10/2025", "24.10.25"
          (a two-digit year means 20xx). Return it as YYYY-MM-DD.
        - A product line has the name on the left and the line total on the right:
          "BANAANI                    1,62"
        - The next line may hold the quantity and the unit price:
          "  0,857 KG           1,89 EUR/KG"  -> amount 0.857, unit "kg", unit_price 1.89, price 1.62
          "  2 KPL              0,79 EUR/KPL" -> amount 2,     unit "kpl", unit_price 0.79, price 1.58
          "  0,824 kg x  1,19 EUR/kg"          -> amount 0.824, unit "kg",  unit_price 1.19
          "  4 x 0,59 EUR"                     -> amount 4,     unit "kpl", unit_price 0.59, price 2.36
        - When there is no such line: amount 1, unit "kpl", unit_price equal to price.
        - Discounts. A receipt prints them on their own line with a negative amount,
          for example "Alennus 20%  -0,36", "Lidl Plus -saastosi  -1,00",
          "4 kpl 2 eurolla  -0,36". There are two kinds, and you must tell them apart:

          a) The discount belongs to the ONE product printed directly above it.
             Do NOT return it as its own line. Instead fold it into that product:
               - price          = printed price minus the discount (1,79 - 0,36 = 1.43)
               - unit_price     = the price per unit as printed, before the discount (1.79)
               - discount_price = the size of the discount, positive (0.36)
               - is_discount    = true

          b) The discount belongs to the WHOLE basket, not to one product. A loyalty
             discount such as "Lidl Plus -saastosi" is this kind. Return it as its own
             line: name as printed, is_discount true, price negative (-1.00),
             discount_price positive (1.00), amount 1, unit "kpl".

          If you are not sure which kind it is, use (b).
        - The PRODUCT LIST ends at the total line ("YHTEENSA" / "YHTEENSÄ"). Never take a
          product from below that line — it holds payment, card, VAT and bonus data.
          You may still read the date and the total from there.
        - Skip deposit lines (PANTTI) only if they have no price. If they have a price, keep them.
        - Never invent a product that is not printed on the receipt. If the image is unreadable
          in one place, leave that line out rather than guessing a name.
        - After folding the discounts in, the sum of all price values must still equal
          the receipt total. Check this before you answer.
        TEXT;
    }

    /**
     * @param  array<int, string>  $categories
     */
    private function instruction(array $categories): string
    {
        $fallback = (string) config('receipts.fallback_category');

        return sprintf(
            "Read this receipt and return every product line.\n\n"
            ."Use only these categories: %s.\n"
            .'If a product does not fit any of them, use "%s".',
            implode(', ', $categories),
            $fallback,
        );
    }
}
