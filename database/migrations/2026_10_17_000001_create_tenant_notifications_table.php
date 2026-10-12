<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Notifikasi dashboard untuk pemilik/admin workspace (DEC-16). Bersama per workspace. Tidak pernah memuat isi pesan
     * pelanggan. `dedupe_key` + `occurrences` mencegah banjir: kejadian berulang pada jendela yang sama menaikkan
     * hitungan, bukan membuat baris baru. Dihapus otomatis setelah retention.notification_days.
     */
    public function up(): void
    {
        Schema::create('tenant_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('severity', 10); // info | warning | critical
            $table->string('title', 150);
            $table->text('body')->nullable();
            $table->string('action_route', 60)->nullable(); // nama route /app yang relevan
            $table->string('dedupe_key', 120)->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('last_occurred_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'dedupe_key']);
            $table->index(['tenant_id', 'read_at', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_notifications');
    }
};
