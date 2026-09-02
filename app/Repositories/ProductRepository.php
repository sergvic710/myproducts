<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DataObjects\ParsedItem;
use App\DataObjects\ParsedReceipt;
use App\Exceptions\ReceiptParseException;
use App\Models\Category;
use App\Models\History;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

class ProductRepository
{
    public static function getAllProducts()
    {
        return Product::orderBy('name', 'asc')->get();
    }

    /**
     * Write a parsed receipt to the database.
     *
     * Shops and categories are never created here — the parser may only return
     * values that already exist. Missing categories fall back to the category
     * named in config('receipts.fallback_category').
     *
     * @return int number of saved line items
     */
    public function saveReceipt(ParsedReceipt $receipt, string $filename): int
    {
        $shop = Shop::whereRaw('LOWER(name) = ?', [mb_strtolower($receipt->shop)])->first();

        if (! $shop) {
            throw ReceiptParseException::unknownShop($receipt->shop);
        }

        $categories = Category::pluck('id', 'name');
        $fallbackName = (string) config('receipts.fallback_category');
        $fallbackId = $this->fallbackCategoryId($categories, $fallbackName);

        return DB::transaction(function () use ($receipt, $filename, $shop, $categories, $fallbackId): int {
            $saved = 0;

            foreach ($receipt->items as $item) {
                $categoryId = $this->categoryId($categories, $item->category, $fallbackId);
                $unit = Unit::firstOrCreate(['name' => $item->unit]);
                $product = $this->product($item, $categoryId, $unit->id);

                History::updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'date' => $receipt->date->toDateString(),
                        'shop_id' => $shop->id,
                        'filename' => $filename,
                    ],
                    [
                        'price' => $item->unitPrice,
                        'amount' => $item->amount,
                        'total' => $item->price,
                        'is_discount' => $item->isDiscount ? 1 : 0,
                        'discount_price' => $item->discountPrice,
                    ]
                );

                $saved++;
            }

            return $saved;
        });
    }

    /**
     * Products are matched by name across all shops, the same as before.
     * Note: `shop_id` is in Product::$fillable but the products table has no
     * such column, so it must not be passed here.
     */
    private function product(ParsedItem $item, int $categoryId, int $unitId): Product
    {
        return Product::firstOrCreate(
            ['name' => $item->name],
            [
                'category_id' => $categoryId,
                'unit_id' => $unitId,
                'count' => 1,
            ]
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<string, int>  $categories
     */
    private function categoryId($categories, string $name, int $fallbackId): int
    {
        if ($name === '') {
            return $fallbackId;
        }

        if ($categories->has($name)) {
            return (int) $categories->get($name);
        }

        // Case-insensitive second try before giving up on the name.
        foreach ($categories as $categoryName => $id) {
            if (mb_strtolower((string) $categoryName) === mb_strtolower($name)) {
                return (int) $id;
            }
        }

        return $fallbackId;
    }

    /**
     * @param  \Illuminate\Support\Collection<string, int>  $categories
     */
    private function fallbackCategoryId($categories, string $fallbackName): int
    {
        if ($categories->has($fallbackName)) {
            return (int) $categories->get($fallbackName);
        }

        throw ReceiptParseException::missingFallbackCategory($fallbackName);
    }
}
