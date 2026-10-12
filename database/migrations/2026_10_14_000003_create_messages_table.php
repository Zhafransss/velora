<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Pesan percakapan (FR-011). Status dipisah per arah supaya "AI selesai" tidak tercampur dengan "terkirim":
     *   inbound : received -> processing -> answered | failed | quota_exceeded | skipped
     *   outbound: pending (jawaban sudah ada, belum terkirim) -> sending -> sent | failed
     * Idempotency (FR-012): pesan masuk yang sama (channel + external id) hanya tersimpan sekali.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 10); // inbound | outbound
            $table->string('external_message_id', 100)->nullable(); // id WhatsApp
            $table->text('body');
            $table->string('status', 20);
            $table->string('source', 10)->nullable(); // outbound: ai | fallback
            $table->string('error_type', 40)->nullable(); // alasan gagal/dilewati
            $table->foreignId('in_reply_to_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('provider', 20)->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedSmallInteger('send_attempts')->default(0);
            $table->boolean('is_test')->default(false); // penanda pesan uji (FR-011)
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // NULL (pesan keluar tanpa id) boleh berulang; id WhatsApp pesan masuk unik per percakapan.
            $table->unique(['conversation_id', 'direction', 'external_message_id']);
            $table->index(['conversation_id', 'id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
