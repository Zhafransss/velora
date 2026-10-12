<?php

namespace App\Console\Commands;

use App\Services\Ai\AiGateway;
use App\Services\Ai\AiGatewayException;
use App\Services\Ai\AiRequest;
use Illuminate\Console\Command;

/** Cek cepat bahwa API key dan model berfungsi. Tidak butuh tenant; tidak menyimpan apa pun ke DB. */
class AiPing extends Command
{
    protected $signature = 'ai:ping';

    protected $description = 'Kirim satu prompt kecil lewat AI gateway untuk memverifikasi konfigurasi provider.';

    public function handle(AiGateway $gateway): int
    {
        try {
            $r = $gateway->generate(new AiRequest(
                system: 'Jawab singkat dalam satu kalimat.',
                messages: [['role' => 'user', 'content' => 'Balas dengan kata: siap']],
            ), 'test');
        } catch (AiGatewayException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("OK via {$r->provider} ({$r->model}) dalam {$r->latencyMs} ms, percobaan: {$r->attempts}"
            . ($r->fallbackUsed ? ' [fallback dipakai]' : ''));
        $this->line('Token masuk/keluar: ' . ($r->inputTokens ?? '?') . ' / ' . ($r->outputTokens ?? '?'));
        $this->line('Jawaban: ' . $r->text);

        return self::SUCCESS;
    }
}
