<?php

namespace App\Services\Ai\Accuracy;

/**
 * Penilai jawaban berbasis aturan (transparan, deterministik) untuk uji akurasi bot (TC-ACC-01).
 *
 * Aturan `expect` pada sebuah kasus:
 *   all_of         : daftar; tiap elemen string ATAU array alternatif (salah satu cukup). Semua elemen harus muncul.
 *   any_of         : minimal salah satu string muncul.
 *   none_of        : tidak satu pun string boleh muncul.
 *   regex_none     : tidak satu pun pola regex (dijalankan pada teks ternormalisasi) boleh cocok.
 *   admits_unknown : jawaban harus mengakui tidak tahu / tidak punya informasinya.
 *
 * Teks dinormalisasi: huruf kecil, pemisah ribuan angka dibuang ("Rp25.000" -> "rp25000"), markdown tebal dibuang.
 * Keterbatasan: penilaian string bisa keliru pada parafrase tak terduga — jawaban yang gagal harus ditinjau manusia.
 */
class AnswerChecker
{
    /** Frasa yang menandakan bot mengakui tidak tahu. */
    private const UNKNOWN_PATTERN = '/(tidak|belum|kurang)\s+(tahu|memiliki|punya|ada|bisa memastikan|dapat memastikan|dapat memberikan|bisa memberikan|diketahui|ditentukan|tersedia informasi)|tidak ada informasi|belum ada informasi|tidak tercantum|belum tercantum|maaf|silakan hubungi|silahkan hubungi|hubungi (kami|langsung)|tanyakan langsung/u';

    /** @return array{passed: bool, reasons: list<string>} */
    public function check(string $answer, array $expect): array
    {
        $text = self::normalize($answer);
        $reasons = [];

        if ($text === '') {
            return ['passed' => false, 'reasons' => ['Jawaban kosong.']];
        }

        foreach ($expect['all_of'] ?? [] as $required) {
            $alternatives = (array) $required;

            if (! $this->containsAny($text, $alternatives)) {
                $reasons[] = 'Tidak memuat: ' . implode(' / ', $alternatives);
            }
        }

        if (! empty($expect['any_of']) && ! $this->containsAny($text, $expect['any_of'])) {
            $reasons[] = 'Tidak memuat salah satu dari: ' . implode(' / ', $expect['any_of']);
        }

        foreach ($expect['none_of'] ?? [] as $forbidden) {
            if (str_contains($text, self::normalize($forbidden))) {
                $reasons[] = "Memuat teks terlarang: {$forbidden}";
            }
        }

        foreach ($expect['regex_none'] ?? [] as $pattern) {
            if (@preg_match('/' . $pattern . '/u', $text) === 1) {
                $reasons[] = "Cocok dengan pola terlarang: {$pattern}";
            }
        }

        if (! empty($expect['admits_unknown']) && preg_match(self::UNKNOWN_PATTERN, $text) !== 1) {
            $reasons[] = 'Tidak mengakui bahwa informasinya tidak diketahui.';
        }

        return ['passed' => $reasons === [], 'reasons' => $reasons];
    }

    public static function normalize(string $text): string
    {
        $text = mb_strtolower(str_replace(["\u{00A0}", '*', '_'], [' ', '', ''], $text));
        // Pemisah ribuan di antara angka: 1.500.000 / 1,500,000 -> 1500000
        $text = preg_replace('/(?<=\d)[.,](?=\d{3}(?!\d))/', '', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** @param list<string> $needles */
    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, self::normalize($needle))) {
                return true;
            }
        }

        return false;
    }
}
