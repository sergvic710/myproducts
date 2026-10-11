<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per receipt pushed to Wallet.
 *
 * `filename` is unique for the same reason it is the dedupe key on histories:
 * the row is claimed before the API call, so a retried job can tell a receipt
 * that was already sent from one that never was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_records', function (Blueprint $table): void {
            $table->id();
            $table->string('filename', 255)->unique();
            $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->date('record_date');
            $table->string('wallet_record_id', 64)->nullable();
            $table->string('status', 16)->default('pending');
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_records');
    }
};
