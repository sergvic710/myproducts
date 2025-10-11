<?php

namespace App\Repositories;

use App\Models\Category;
use App\Models\History;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Unit;
use Illuminate\Support\Facades\Date;

class ProductRepository
{

    public static function getAllProducts() {
        return Product::orderBy('name', 'asc')->get();
    }
    public static function productsSave( array $cart, string $filename)
    {
        foreach ($cart['products'] as $item) {
            $unit = Unit::where('name', $item['unit'])->first();
            if( !$unit ) {
                $unit = Unit::create(['name' => $item['unit']]);
            }

            $category = Category::where('name', 'default')->first();
            if( !$category ) {
                $unit = Category::create(['name' => 'default']);
            }
            $product = Product::where('name', $item['name'])->first();
            if( !$product ) {
                $product = Product::create([
                    'name' => $item['name'],
                    'category_id' => $category->id,
                    'unit_id' => $unit->id,
                    'count' => 1
                ]);
            }

            if( $unit && $product ) {
                $date = new \DateTime($cart['date']);
                History::updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'date' => $date,
                        'shop_id' => $cart['shop_id'],
                        'filename' => $filename
                    ],
                    [
                        'price' => $item['priceUnit'],
                        'amount' => $item['amount'],
                        'total' => $item['price']
                    ]
                );
            }

        }
    }

}
