<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Log teknis tiap pemanggilan AI (token, latensi, biaya, galat). TIDAK menyimpan isi pesan/prompt
     * (privasi pelanggan tenant). Ini bukan ledger kuota — itu `usage_events` di Tahap 3.
     */
    public function up(): void
    {
        Schema::create('ai_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 20); // test | chat
            $table->string('provider', 20)->nullable(); // provider yang menjawab (atau yang terakhir dicoba)
            $table->string('model', 100)->nullable();
            $table->string('status', 10); // success | failed
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('latency_ms');
            $table->unsignedTinyInteger('attempts');
            $table->boolean('fallback_used')->default(false);
            $table->string('error_type', 30)->nullable();
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_calls');
    }
};
