<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class History extends Model
{
    /** @use HasFactory<\Database\Factories\HistoryFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id',
        'shop_id',
        'price',
        'amount',
        'total',
        'date',
        'filename',
        'is_discount',
        'discount_price'
    ];
    public function product() : \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->BelongsTo(Product::class);
    }
    public function shop() : \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->BelongsTo(Shop::class);
    }

}
