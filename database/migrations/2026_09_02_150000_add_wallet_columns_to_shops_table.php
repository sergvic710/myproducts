<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wallet has no template API, so a shop row carries what a Wallet transaction
 * template would have carried. A shop with no `wallet_account` is simply never
 * pushed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->string('wallet_account', 255)->nullable()->after('address');
            $table->string('wallet_category', 255)->nullable()->after('wallet_account');
            $table->string('wallet_payee', 255)->nullable()->after('wallet_category');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn(['wallet_account', 'wallet_category', 'wallet_payee']);
        });
    }
};
