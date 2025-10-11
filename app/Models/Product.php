<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;


class Product extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory;
    use InteractsWithMedia;

    protected $fillable = [
        'name',
        'category_id',
        'unit_id',
        'shop_id',
        'count',
    ];

    public function category() : \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->BelongsTo(Category::class);
    }
    public function unit() : \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->BelongsTo(Unit::class);
    }
}
