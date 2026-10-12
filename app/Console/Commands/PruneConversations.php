<?php

namespace App\Console\Commands;

use App\Services\Admin\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Retensi data percakapan (DEC-15): menghapus pesan, percakapan yang sudah kosong, dan riwayat tes chatbot yang lebih
 * tua dari N hari (config/retention.php, bawaan 30). Dijadwalkan harian. Penghapusan bertahap agar tidak mengunci tabel.
 *
 * TIDAK menghapus: usage_events/daily_usage (ledger penagihan), audit_logs, ai_calls (log teknis tanpa isi pesan).
 * Cadangan (backup) harus berumur <= retensi agar data benar-benar hilang.
 */
class PruneConversations extends Command
{
    private const CHUNK = 1000;

    protected $signature = 'conversations:prune
        {--dry-run : Hanya hitung yang akan dihapus}
        {--days= : Ganti jumlah hari retensi (bawaan: config retention.conversation_days)}';

    protected $description = 'Hapus percakapan dan riwayat tes yang melewati masa retensi.';

    public function handle(AuditLogger $audit): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : (int) config('retention.conversation_days');

        // Pengaman: nilai 0/negatif akan menghapus data yang masih baru.
        if ($days < 1) {
            $this->error('Jumlah hari retensi harus minimal 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $dry = (bool) $this->option('dry-run');

        $counts = [
            'messages' => $this->purge('messages', fn ($q) => $q->where('created_at', '<', $cutoff), $dry),
            'ai_test_messages' => $this->purge('ai_test_messages', fn ($q) => $q->where('created_at', '<', $cutoff), $dry),
        ];

        // Percakapan tanpa satu pun pesan yang masih dalam masa retensi (kosong, atau semua pesannya sudah usang) dan
        // yang aktivitas terakhirnya sudah lewat. Kondisi yang sama berlaku untuk dry-run dan eksekusi.
        $counts['conversations'] = $this->purge('conversations', function ($q) use ($cutoff) {
            $q->whereNotExists(fn ($m) => $m->select(DB::raw(1))->from('messages')
                ->whereColumn('messages.conversation_id', 'conversations.id')->where('messages.created_at', '>=', $cutoff))
                ->whereRaw('coalesce(last_message_at, created_at) < ?', [$cutoff]);
        }, $dry);

        // Notifikasi dashboard punya masa simpan sendiri (retention.notification_days); tidak memuat isi pesan.
        $notificationCutoff = now()->subDays(max(1, (int) config('retention.notification_days')));
        $counts['tenant_notifications'] = $this->purge('tenant_notifications', fn ($q) => $q->where('created_at', '<', $notificationCutoff), $dry);

        $total = array_sum($counts);
        $label = $dry ? 'akan dihapus' : 'dihapus';
        $this->info("Retensi {$days} hari (sebelum {$cutoff->format('d M Y H:i')}): {$counts['messages']} pesan, {$counts['conversations']} percakapan, {$counts['ai_test_messages']} riwayat tes {$label}; {$counts['tenant_notifications']} notifikasi lama {$label}.");

        if (! $dry && $total > 0) {
            // Hanya angka agregat — tidak pernah isi pesan.
            Log::info('retention.pruned', $counts + ['days' => $days]);
            $audit->record('system.retention_pruned', null, null, [], $counts, "Retensi {$days} hari");
        }

        return self::SUCCESS;
    }

    /** @param callable(\Illuminate\Database\Query\Builder): mixed $scope */
    private function purge(string $table, callable $scope, bool $dry): int
    {
        $base = fn () => tap(DB::table($table), fn ($q) => $scope($q));

        if ($dry) {
            return $base()->count();
        }

        $deleted = 0;

        do {
            $ids = $base()->orderBy('id')->limit(self::CHUNK)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += DB::table($table)->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::CHUNK);

        return $deleted;
    }
}
