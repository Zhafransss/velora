<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * Enkripsi berkas backup berbentuk aliran: gzip lalu AES-256-GCM per potongan (konstruksi "STREAM"), memakai openssl
 * (tersedia di mana-mana; tidak butuh ekstensi sodium).
 *
 * Format: "VBK1" | prefix acak 8 byte | frame*  ; frame = panjang(4, big-endian) | ciphertext | tag(16)
 *  - nonce  = prefix(8) + nomor frame(4): tidak pernah berulang dalam satu berkas, dan prefix acak per berkas.
 *  - AAD    = "VBK1" + penanda frame-terakhir. Frame terakhir WAJIB bertanda final: berkas yang terpotong (upload
 *    terputus) atau frame yang dibuang/ditukar urutannya GAGAL didekripsi, bukan diam-diam menghasilkan dump parsial.
 * Kunci backup berbeda dari kunci auth WhatsApp (AUTH_ENCRYPTION_KEYS); keduanya harus disimpan di luar server.
 */
class BackupCipher
{
    public const MAGIC = 'VBK1';

    private const FRAME = 65536;       // plaintext (hasil gzip) per frame
    private const TAG = 16;
    private const MAX_FRAME_BYTES = self::FRAME + self::TAG + 1024;

    /** @return string kunci acak baru dalam format .env (base64 32 byte) */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    /** @throws RuntimeException bila kunci kosong atau bukan 32 byte */
    public static function parseKey(?string $encoded): string
    {
        $key = is_string($encoded) && $encoded !== '' ? base64_decode($encoded, true) : false;

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY harus base64 dari tepat 32 byte (buat dengan: php artisan backup:key).');
        }

        return $key;
    }

    /**
     * Pembungkus tulis: write() berkali-kali lalu close().
     *
     * @return object{write: callable(string): void, close: callable(): int}
     */
    public static function writer(string $path, string $key): object
    {
        return new class($path, $key) {
            private $handle;
            private string $prefix;
            private int $counter = 0;
            private string $buffer = '';
            private $deflate;
            private int $bytes = 0;

            public function __construct(string $path, private string $key)
            {
                $this->handle = fopen($path, 'wb') ?: throw new RuntimeException('Tidak dapat menulis berkas backup.');
                $this->prefix = random_bytes(8);
                $this->deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
                $this->bytes += fwrite($this->handle, BackupCipher::MAGIC . $this->prefix);
            }

            public function write(string $chunk): void
            {
                $this->buffer .= deflate_add($this->deflate, $chunk, ZLIB_NO_FLUSH);

                while (strlen($this->buffer) > 65536) {
                    $this->frame(substr($this->buffer, 0, 65536), false);
                    $this->buffer = substr($this->buffer, 65536);
                }
            }

            /** @return int ukuran berkas akhir (byte) */
            public function close(): int
            {
                $this->buffer .= deflate_add($this->deflate, '', ZLIB_FINISH);

                while (strlen($this->buffer) > 65536) {
                    $this->frame(substr($this->buffer, 0, 65536), false);
                    $this->buffer = substr($this->buffer, 65536);
                }

                $this->frame($this->buffer, true); // frame final (boleh kosong)
                fclose($this->handle);

                return $this->bytes;
            }

            private function frame(string $plain, bool $final): void
            {
                $nonce = $this->prefix . pack('N', $this->counter++);
                $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, BackupCipher::MAGIC . ($final ? 'F' : 'M'), 16);

                if ($cipher === false) {
                    throw new RuntimeException('Enkripsi backup gagal.');
                }

                $this->bytes += fwrite($this->handle, pack('N', strlen($cipher)) . $cipher . $tag);
            }
        };
    }

    /**
     * Dekripsi + gunzip sebuah berkas, mengirim hasilnya ke $sink per potongan.
     *
     * @param  callable(string): void  $sink
     * @throws RuntimeException kunci salah, berkas diubah/terpotong/bukan backup Velora
     */
    public static function decrypt(string $path, string $key, callable $sink): void
    {
        $in = @fopen($path, 'rb') ?: throw new RuntimeException('Berkas backup tidak dapat dibuka.');

        try {
            if (fread($in, 4) !== self::MAGIC || strlen($prefix = (string) fread($in, 8)) !== 8) {
                throw new RuntimeException('Bukan berkas backup Velora (header tidak dikenal).');
            }

            $inflate = inflate_init(ZLIB_ENCODING_GZIP);
            $counter = 0;

            while (true) {
                $header = fread($in, 4);

                if ($header === '' || $header === false) {
                    // Berkas berakhir tanpa frame final: terpotong.
                    throw new RuntimeException('Berkas backup terpotong (frame akhir tidak ditemukan).');
                }

                $length = strlen($header) === 4 ? unpack('N', $header)[1] : -1;

                if ($length < 0 || $length > self::MAX_FRAME_BYTES) {
                    throw new RuntimeException('Berkas backup rusak (ukuran frame tidak valid).');
                }

                $cipher = $length > 0 ? (string) fread($in, $length) : '';
                $tag = (string) fread($in, self::TAG);

                if (strlen($cipher) !== $length || strlen($tag) !== self::TAG) {
                    throw new RuntimeException('Berkas backup terpotong.');
                }

                $final = feof($in) || fread($in, 1) === '' ;
                $final || fseek($in, -1, SEEK_CUR);

                $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $prefix . pack('N', $counter++), $tag, self::MAGIC . ($final ? 'F' : 'M'));

                if ($plain === false) {
                    throw new RuntimeException('Dekripsi gagal: kunci salah, berkas diubah, atau terpotong.');
                }

                $out = $plain === '' ? '' : inflate_add($inflate, $plain, $final ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);

                if ($out !== '' && $out !== false) {
                    $sink($out);
                }

                if ($final) {
                    return;
                }
            }
        } finally {
            fclose($in);
        }
    }
}
