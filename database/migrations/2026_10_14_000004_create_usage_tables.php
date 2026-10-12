<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Metering kuota harian (FR-012).
     *  - usage_events : ledger append-only (audit). Koreksi lewat event baru, bukan mengubah/menghapus baris.
     *  - daily_usage  : agregat per tenant per tanggal (zona waktu tenant). Dinaikkan secara atomik dengan
     *                   UPDATE bersyarat (used < limit); dapat direkonsiliasi terhadap ledger.
     */
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('usage_date');
            $table->string('type', 20); // reserve | release | adjustment
            $table->integer('units'); // +1 reserve, -1 release, +/-n adjustment
            // Event yang sama tidak boleh memakai kuota dua kali.
            $table->string('idempotency_key', 150)->unique();
            $table->string('purpose', 10)->default('chat'); // chat | test
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('note', 200)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'usage_date']);
        });

        Schema::create('daily_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('usage_date');
            $table->integer('used')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'usage_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_usage');
        Schema::dropIfExists('usage_events');
    }
};
