<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Query lintas tenant yang mencari pesan menurut status + umur (penyapu `messages:sweep-stuck` tiap 5 menit, widget
 * admin "pesan menggantung" yang polling) tidak bisa memakai index (tenant_id, status) dan membaca seluruh tabel.
 * Diukur pada 480 ribu pesan: widget 227 ms -> 1 ms, penyapu 120 ms -> 59 ms (waktu kini mengikuti jumlah pesan macet,
 * bukan ukuran tabel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['status', 'updated_at']);
        });
    }
};
