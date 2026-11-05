<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sgroup_sessions', function (Blueprint $table) {
            $table->id();
            // Both are login secrets, stored encrypted (see the model casts),
            // hence text: the ciphertext is far longer than the value.
            $table->text('session_id');
            $table->text('xsrf_token');
            // The chat that sent the session — where expiry and new receipts are reported.
            $table->foreignId('telegraph_chat_id')->nullable()->constrained('telegraph_chats')->nullOnDelete();
            $table->timestamp('last_ok_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sgroup_sessions');
    }
};
