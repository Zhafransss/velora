<?php

namespace App\Console\Commands;

use App\Services\Admin\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Menghapus kredensial WhatsApp YATIM: baris di wa_auth_* yang channelnya sudah tidak ada (mis. tenant dihapus lewat
 * cascade DB, yang tidak memicu event model). Kredensial itu memberi akses ke akun WhatsApp, jadi tidak boleh tersisa.
 *
 * Konservatif: hanya id berbentuk ULID (channel buatan Laravel) dan yang sudah lebih tua dari 1 hari — id lain (mis.
 * "poc_a" dari skrip PoC konektor) dan channel yang baru dibuat tidak pernah disentuh.
 */
class PruneWhatsAppAuth extends Command
{
    private const ULID = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    protected $signature = 'whatsapp:prune-auth {--dry-run : Hanya hitung}';

    protected $description = 'Hapus kredensial WhatsApp milik channel yang sudah tidak ada.';

    public function handle(AuditLogger $audit): int
    {
        $cutoff = now()->subDay();
        $existing = DB::table('channels')->pluck('public_id')->all();

        $orphans = DB::table('wa_auth_creds')->where('updated_at', '<', $cutoff)->pluck('channel_id')
            ->merge(DB::table('wa_auth_keys')->where('updated_at', '<', $cutoff)->distinct()->pluck('channel_id'))
            ->unique()
            ->filter(fn ($id) => preg_match(self::ULID, (string) $id) === 1 && ! in_array($id, $existing, true))
            ->values();

        $rows = 0;

        foreach ($orphans as $id) {
            // Kunci/creds yang diperbarui belakangan menandakan konektor masih memakainya: lewati, jangan dihapus.
            $recent = DB::table('wa_auth_creds')->where('channel_id', $id)->where('updated_at', '>=', $cutoff)->exists()
                || DB::table('wa_auth_keys')->where('channel_id', $id)->where('updated_at', '>=', $cutoff)->exists();

            if ($recent) {
                continue;
            }

            if ($this->option('dry-run')) {
                $rows += DB::table('wa_auth_keys')->where('channel_id', $id)->count() + DB::table('wa_auth_creds')->where('channel_id', $id)->count();

                continue;
            }

            $rows += DB::table('wa_auth_keys')->where('channel_id', $id)->delete() + DB::table('wa_auth_creds')->where('channel_id', $id)->delete();
        }

        $label = $this->option('dry-run') ? 'akan dihapus' : 'dihapus';
        $this->info("{$orphans->count()} channel yatim, {$rows} baris kredensial {$label}.");

        if (! $this->option('dry-run') && $rows > 0) {
            $audit->record('system.whatsapp_auth_pruned', null, null, [], ['channels' => $orphans->count(), 'rows' => $rows], 'Kredensial channel yatim');
        }

        return self::SUCCESS;
    }
}
