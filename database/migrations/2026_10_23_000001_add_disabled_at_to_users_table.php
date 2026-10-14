<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akun workspace yang dinonaktifkan admin platform (FR-014, DEC-20): tidak bisa login dan sesi aktifnya diputus,
 * tetapi data dan keanggotaannya tetap ada sehingga bisa diaktifkan kembali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('disabled_at')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('disabled_at');
        });
    }
};
