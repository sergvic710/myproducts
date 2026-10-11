<?php

declare(strict_types=1);

/**
 * One-off repair for three receipts saved with the wrong year.
 *
 * Cause: the parser prompt never told the model what year it was, and every
 * date example in it read 2025, so "11.09.26" and "03.09.26" were both saved
 * as 2025. That is fixed in AiReceiptParser; this corrects the rows that were
 * already written, which re-parsing does not touch.
 *
 * The two 03.09 rows are byte-identical uploads of one receipt (same md5,
 * different Telegram file id, so the filename dedupe key missed them). The
 * earlier upload is kept because it is the one already pushed to Wallet.
 *
 * Run:      php artisan tinker --execute="require 'database/repairs/2026_09_13_fix_receipt_dates.php';"
 * Undo:     the affected rows were backed up first; see the scratchpad JSON.
 *
 * Safe to run twice: each step is a no-op once applied.
 */

use Illuminate\Support\Facades\DB;

DB::transaction(function (): void {
    // The Lidl receipt reported by hand: the paper says 11.09.26, and the
    // card block agrees. Was stored 2025-09-17.
    $lidl = DB::table('histories')
        ->where('filename', 'photo_c81939fbcb6534cc.jpg')
        ->whereDate('date', '2025-09-17')
        ->update(['date' => '2026-09-11 00:00:00', 'updated_at' => now()]);

    // The kept copy of the duplicated receipt. Paper says 03.09.26.
    $kept = DB::table('histories')
        ->where('filename', 'photo_f0bb957e03984d49.jpg')
        ->whereDate('date', '2025-09-03')
        ->update(['date' => '2026-09-03 00:00:00', 'updated_at' => now()]);

    // The second upload of that same image. Its line items are a worse read of
    // the same receipt (32.82 against the printed 41.43), and nothing in
    // wallet_records refers to it, so it can go.
    $duplicate = DB::table('histories')
        ->where('filename', 'photo_3c3383f386d56ce4.jpg')
        ->delete();

    // A second byte-identical pair, found by hashing every stored file rather
    // than trusting the filename key. Both copies were saved, double-counting
    // 22.01. Keeping the one wallet_records refers to, as above.
    $duplicate += DB::table('histories')
        ->where('filename', 'photo_7c45ffa5f1ec9d3f.jpg')
        ->delete();

    printf(
        "dates corrected: %d (Lidl 11 Sep) + %d (3 Sep); duplicate rows removed: %d\n",
        $lidl,
        $kept,
        $duplicate,
    );
});
