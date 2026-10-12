<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Berapa kali pemilik workspace memproses ulang pesan masuk secara manual. Dipakai membentuk kunci kuota yang
     * baru per percobaan: kunci lama sudah "terpakai" (dan unitnya dikembalikan), sehingga memakainya lagi akan
     * dianggap duplikat dan AI terpanggil tanpa menghitung kuota.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedSmallInteger('process_attempts')->default(0)->after('send_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('process_attempts');
        });
    }
};
