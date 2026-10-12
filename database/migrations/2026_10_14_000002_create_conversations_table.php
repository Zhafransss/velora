<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Satu percakapan = satu pelanggan pada satu kanal. Balasan selalu lewat channel milik percakapan ini
     * (FR-009.4), tidak pernah ditebak dari nomor penerima.
     * Retensi data belum diputuskan (OQ-05).
     */
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('customer_external_id', 100); // jid tujuan balasan (bisa @lid)
            $table->string('customer_alt_id', 100)->nullable(); // nomor telepon bila diketahui
            $table->string('customer_name', 150)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['channel_id', 'customer_external_id']);
            $table->index(['tenant_id', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
