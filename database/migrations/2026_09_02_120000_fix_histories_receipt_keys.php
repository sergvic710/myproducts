<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Telegram file names can be longer than the old varchar(100).
        Schema::table('histories', function (Blueprint $table): void {
            $table->string('filename', 255)->nullable()->change();
        });

        Schema::table('histories', function (Blueprint $table): void {
            $table->index('filename', 'histories_filename_index');
        });

        // Backs the updateOrCreate() match set in ProductRepository::saveReceipt().
        // Skipped when the existing rows already contain duplicates.
        if ($this->hasDuplicates()) {
            return;
        }

        Schema::table('histories', function (Blueprint $table): void {
            $table->unique(
                ['product_id', 'date', 'shop_id', 'filename'],
                'histories_receipt_line_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('histories', function (Blueprint $table): void {
            if (Schema::hasIndex('histories', 'histories_receipt_line_unique')) {
                $table->dropUnique('histories_receipt_line_unique');
            }

            $table->dropIndex('histories_filename_index');
        });

        Schema::table('histories', function (Blueprint $table): void {
            $table->string('filename', 100)->nullable()->change();
        });
    }

    private function hasDuplicates(): bool
    {
        $duplicates = DB::table('histories')
            ->select('product_id', 'date', 'shop_id', 'filename')
            ->groupBy('product_id', 'date', 'shop_id', 'filename')
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            echo "  histories has duplicate receipt lines — unique index skipped.\n";

            return true;
        }

        return false;
    }
};
