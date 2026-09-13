<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "I have already bought this, stop telling me about it."
 *
 * Only the day of the tap is stored. The hide window is derived at read time as
 * `snoozed_at + the product's current interval`, so a snooze always agrees with
 * the prediction it came from — storing a computed `snoozed_until` would freeze
 * the interval as it was at tap time and drift out of agreement with it.
 *
 * There is deliberately no invalidation code: a purchase on day P >= snoozed_at
 * moves the next due date to P + interval, which is never earlier than the end
 * of the snooze window. A snooze therefore cannot outlive the receipt that
 * makes it irrelevant, and cannot suppress an item that is genuinely due.
 *
 * @property int $product_id
 * @property CarbonImmutable $snoozed_at
 */
class ProductSnooze extends Model
{
    protected $fillable = [
        'product_id',
        'snoozed_at',
    ];

    protected $casts = [
        'snoozed_at' => 'immutable_date',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
