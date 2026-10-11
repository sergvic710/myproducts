<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per product that has been dismissed from the shopping list.
 *
 * `product_id` is unique because the row is upserted on every tap — the table
 * is bounded by the product count, never grows, and needs no cleanup job. Only
 * the day is stored; see App\Models\ProductSnooze for why there is no
 * `snoozed_until` column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_snoozes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('snoozed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_snoozes');
    }
};
