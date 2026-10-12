<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** Riwayat backup + hasil uji restore (NFR-005). Tanpa isi data: hanya metadata dan hitungan baris per tabel. */
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->string('file')->nullable();
            $table->string('disk', 50);
            $table->string('status', 20)->index(); // running | succeeded | failed
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->json('manifest')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->json('verify_result')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
