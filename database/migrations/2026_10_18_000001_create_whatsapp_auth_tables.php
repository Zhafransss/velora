<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Penyimpanan auth Baileys (DEC-17): kredensial perangkat WhatsApp + kunci Signal, TERENKRIPSI AES-256-GCM oleh
     * konektor sebelum ditulis. Tabel ini dimiliki skema Laravel, tetapi HANYA konektor yang membaca/menulisnya
     * (disarankan pengguna MySQL khusus dengan hak terbatas pada dua tabel ini — lihat connector/README.md).
     * Laravel tidak pernah membaca isinya. Kunci enkripsi TIDAK ada di database.
     *
     * Catatan penting: kolom id memakai kolase BINER (peka huruf). Id kunci Signal peka huruf; kolase bawaan yang tidak
     * peka huruf akan menabrakkan dua id berbeda dan menimpa kunci secara diam-diam.
     */
    public function up(): void
    {
        $binary = fn ($column) => DB::getDriverName() === 'mysql' ? $column->collation('utf8mb4_bin') : $column;

        Schema::create('wa_auth_creds', function (Blueprint $table) use ($binary) {
            $binary($table->string('channel_id', 64))->primary(); // = channels.public_id (channelId di konektor)
            $table->mediumText('payload');                         // terenkripsi
            $table->string('enc_key_id', 16);                      // id kunci enkripsi yang dipakai (rotasi)
            $table->timestamp('updated_at', 3)->useCurrent();
        });

        Schema::create('wa_auth_keys', function (Blueprint $table) use ($binary) {
            $binary($table->string('channel_id', 64));
            $binary($table->string('key_type', 40));
            $binary($table->string('key_id', 255));
            $table->text('payload');                               // terenkripsi
            $table->string('enc_key_id', 16);
            $table->timestamp('updated_at', 3)->useCurrent();

            $table->primary(['channel_id', 'key_type', 'key_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_auth_keys');
        Schema::dropIfExists('wa_auth_creds');
    }
};
