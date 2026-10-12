<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Jejak audit tindakan administratif platform (FR-014): siapa, kapan, apa, dan nilai sebelum/sesudah.
     * Append-only (model menolak update/delete). Riwayat dipertahankan walau tenant/pengguna dihapus, karena itu
     * relasi memakai nullOnDelete dan nama/email disimpan sebagai snapshot.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_label', 150); // email admin, atau "cli" / "system"
            $table->string('action', 60);       // mis. subscription.activated, tenant.suspended
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tenant_label', 160)->nullable();
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('note', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('action');
            $table->index(['tenant_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
