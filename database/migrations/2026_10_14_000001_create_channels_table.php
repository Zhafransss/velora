<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Kanal pesan tenant (FR-009). `public_id` (ULID, 26 karakter alfanumerik) dipakai sebagai channelId di konektor
     * Baileys — dibuat server, bukan dipilih pengguna. Tidak ada secret/kredensial WhatsApp di sini
     * (kredensial hanya di penyimpanan auth konektor).
     */
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->char('public_id', 26)->unique();
            $table->string('type', 20)->default('whatsapp');
            $table->string('name', 100);
            // created | starting | qr | qr_expired | reconnecting | connected | failed | logged_out | stopped | revoked
            $table->string('status', 20)->default('created');
            $table->string('external_account_id', 100)->nullable(); // nomor/jid akun yang tertaut
            $table->timestamp('last_status_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
