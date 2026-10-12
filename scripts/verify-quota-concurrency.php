<?php
// Bukti konkurensi kuota (FR-012, TC-QUOTA-02) pada database SUNGGUHAN (MySQL): banyak proses PHP berebut jatah satu tenant.
// Jalankan SEBELUM deploy / setelah mengubah QuotaManager:  php scripts/verify-quota-concurrency.php
// Membuat tenant sementara 'Race Test' (slug race-*) dan menghapusnya di akhir. JANGAN dijalankan terhadap database produksi
// yang sedang melayani trafik. Test Pest memakai SQLite satu-koneksi sehingga TIDAK dapat membuktikan hal ini.
chdir(dirname(__DIR__));
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Usage\QuotaManager;

const LIMIT = 25;
const WORKERS = 8;
const UNIQUE_PER_WORKER = 20;   // kunci unik per pekerja -> 160 percobaan berbeda untuk 25 unit
const SHARED_KEYS = 10;         // kunci yang sama dipakai SEMUA pekerja -> tepat 1 yang boleh "granted" per kunci

config(['plans.small.daily_ai_messages' => LIMIT]);

if (($argv[1] ?? '') === 'worker') {
    $tenant = Tenant::findOrFail((int) $argv[2]);
    $no = (int) $argv[3];
    $quota = new QuotaManager();
    $out = ['granted' => 0, 'exceeded' => 0, 'duplicate' => 0, 'granted_keys' => []];

    // Mulai serentak: tunggu sampai detik berikutnya berganti agar semua proses menembak bersamaan.
    $start = (float) $argv[4];
    while (microtime(true) < $start) { usleep(500); }

    for ($i = 0; $i < SHARED_KEYS; $i++) {
        $d = $quota->reserve($tenant, "shared-{$i}");
        $out[$d->status]++;
        $d->granted() && $out['granted_keys'][] = "shared-{$i}";
    }
    for ($i = 0; $i < UNIQUE_PER_WORKER; $i++) {
        $d = $quota->reserve($tenant, "w{$no}-{$i}");
        $out[$d->status]++;
    }
    echo json_encode($out);
    exit(0);
}

// ---- Induk ----
$tenant = Tenant::create(['name' => 'Race Test', 'slug' => 'race-' . bin2hex(random_bytes(4))]);
Subscription::create(['tenant_id' => $tenant->id, 'plan_code' => 'small', 'status' => 'active', 'current_period_start' => now()->subDay(), 'current_period_end' => now()->addDay()]);

$start = microtime(true) + 3.0;
$procs = [];
for ($w = 1; $w <= WORKERS; $w++) {
    $cmd = [PHP_BINARY, __FILE__, 'worker', (string) $tenant->id, (string) $w, (string) $start];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $procs[] = [$p, $pipes];
}

$total = ['granted' => 0, 'exceeded' => 0, 'duplicate' => 0];
$grantedShared = [];
foreach ($procs as [$p, $pipes]) {
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    proc_close($p);
    $r = json_decode($stdout, true);
    if (! is_array($r)) { echo "worker error: ", substr($stderr . $stdout, 0, 300), "\n"; continue; }
    foreach ($total as $k => $_) { $total[$k] += $r[$k]; }
    foreach ($r['granted_keys'] as $k) { $grantedShared[$k] = ($grantedShared[$k] ?? 0) + 1; }
}

$quota = new QuotaManager();
$used = $quota->used($tenant);
$recon = $quota->reconcile($tenant);
$events = DB::table('usage_events')->where('tenant_id', $tenant->id)->count();
$dupKeys = DB::table('usage_events')->where('tenant_id', $tenant->id)->select('idempotency_key')->groupBy('idempotency_key')->havingRaw('count(*) > 1')->count();
$attempts = WORKERS * (SHARED_KEYS + UNIQUE_PER_WORKER);

echo "driver: ", DB::connection()->getDriverName(), "\n";
echo "pekerja paralel: ", WORKERS, ", percobaan total: {$attempts}, batas: ", LIMIT, "\n";
echo "granted={$total['granted']} exceeded={$total['exceeded']} duplicate={$total['duplicate']}\n";
echo "daily_usage.used={$used} | ledger events={$events} | rekonsiliasi: ", json_encode($recon), "\n";
echo "kunci bersama yang 'granted' lebih dari sekali: ", count(array_filter($grantedShared, fn ($n) => $n > 1)), "\n";
echo "kunci idempotency ganda di ledger: {$dupKeys}\n";

$ok = $total['granted'] === LIMIT && $used === LIMIT && $events === LIMIT && $recon['consistent']
    && $dupKeys === 0 && count(array_filter($grantedShared, fn ($n) => $n > 1)) === 0
    && ($total['granted'] + $total['exceeded'] + $total['duplicate']) === $attempts;
echo $ok ? "HASIL: LULUS — tidak ada overshoot, tidak ada penagihan ganda\n" : "HASIL: GAGAL\n";

$tenant->delete(); // cascade: subscriptions, usage_events, daily_usage
echo "data uji dibersihkan: tenant tersisa dengan slug race-* = ", Tenant::where('slug', 'like', 'race-%')->count(), "\n";
