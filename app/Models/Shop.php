<?php

namespace App\Models;

use Database\Factories\ShopFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shop extends Model
{
    /** @use HasFactory<ShopFactory> */
    use HasFactory;

    protected $fillable = ['name', 'wallet_account', 'wallet_category', 'wallet_payee'];

    /**
     * Wallet exposes no template API, so these three columns stand in for the
     * transaction template named after this shop. Without an account there is
     * nothing to post to, so that is what makes the template usable.
     */
    public function hasWalletTemplate(): bool
    {
        return filled($this->wallet_account);
    }
}
