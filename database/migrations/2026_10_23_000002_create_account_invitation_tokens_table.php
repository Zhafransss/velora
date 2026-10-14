<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token undangan akun dari admin platform (broker password `invites`, berlaku 72 jam). Tabel terpisah dari
 * password_reset_tokens agar token reset biasa (60 menit) tidak bisa dipakai lewat halaman undangan, dan sebaliknya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_invitation_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_invitation_tokens');
    }
};
