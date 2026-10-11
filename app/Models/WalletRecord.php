<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record of one receipt being pushed to BudgetBakers Wallet.
 *
 * @property string $filename
 * @property string $status pending|created|skipped|failed
 * @property string|null $wallet_record_id
 */
class WalletRecord extends Model
{
    public const PENDING = 'pending';

    public const CREATED = 'created';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    protected $fillable = [
        'filename',
        'shop_id',
        'amount',
        'record_date',
        'wallet_record_id',
        'status',
        'error',
    ];

    protected $casts = [
        'amount' => 'float',
        'record_date' => 'date',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * A receipt is done with Wallet once it was created there, or once we
     * decided it could not be. Both mean: do not call the API again.
     */
    public function isSettled(): bool
    {
        return in_array($this->status, [self::CREATED, self::SKIPPED], true);
    }
}
