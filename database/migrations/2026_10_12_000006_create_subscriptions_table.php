<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Versi minimal untuk gerbang akses fitur. Plan/entitlement lengkap menyusul di Tahap 4 (FR-004);
     * plan_code sementara berupa string (small | medium | enterprise).
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('plan_code', 30);
            $table->string('status', 20); // trialing | active | past_due | suspended | canceled | expired
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->text('notes')->nullable(); // catatan penagihan manual (DEC-07)
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
