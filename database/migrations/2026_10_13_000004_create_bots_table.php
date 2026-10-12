<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Konfigurasi bot per tenant (FR-006). Dasar kolom mengikuti PRD 9.5; persona, tone dan
     * uncertainty_rule dijadikan kolom sendiri (bukan JSON) karena langsung dipakai penyusun prompt.
     * Tidak ada secret/API key provider di sini — itu hanya di .env server.
     */
    public function up(): void
    {
        Schema::create('bots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->char('public_id', 26)->unique(); // ULID, aman dipakai di URL/webhook publik
            $table->string('name', 100);
            $table->text('persona')->nullable();
            $table->string('tone', 20)->default('ramah'); // ramah | formal | santai | profesional
            $table->string('language', 5)->default('id');
            $table->text('system_instructions')->nullable();
            $table->text('welcome_message');
            $table->text('fallback_message');
            $table->text('uncertainty_rule');
            $table->string('status', 20)->default('draft'); // draft | active | paused
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bots');
    }
};
