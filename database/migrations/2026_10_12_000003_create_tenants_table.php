<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 160)->unique();
            $table->string('status', 20)->default('active');
            // Tanggal kuota harian dihitung berdasar zona waktu tenant (FR-010); waktu lain disimpan UTC.
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
