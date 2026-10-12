<?php

use App\Models\BackupRun;
use App\Services\Backup\BackupCipher;
use App\Services\Backup\BackupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('bk');
    config([
        'backup.disk' => 'bk', 'backup.encryption_key' => BackupCipher::generateKey(), 'backup.retention_days' => 14,
        'backup.max_retention_days' => 30, 'backup.max_age_hours' => 26, 'backup.max_verify_age_days' => 8,
        'backup.verify_database' => 'velora_restore_check',
    ]);
});

function bkDump(string $body = "INSERT INTO t VALUES (1);\n", bool $complete = true): BackupService
{
    return new BackupService(function (callable $sink) use ($body, $complete) {
        $sink("-- MySQL dump\n");
        $sink($body);
        $complete && $sink("-- Dump completed on 2026-10-12\n");
    });
}

function bkDecrypt(string $path, string $key): string
{
    $out = '';
    BackupCipher::decrypt($path, $key, function ($c) use (&$out) { $out .= $c; });

    return $out;
}

function bkFile(string $content, ?string $key = null): string
{
    $path = tempnam(sys_get_temp_dir(), 'bk');
    $w = BackupCipher::writer($path, $key ?? BackupCipher::parseKey(config('backup.encryption_key')));
    $w->write($content);
    $w->close();

    return $path;
}

describe('cipher', function () {
    it('round-trips data of any size, including empty and multi-frame', function (int $size) {
        $key = BackupCipher::parseKey(BackupCipher::generateKey());
        $data = substr(str_repeat('abcdefghij', intdiv($size, 10) + 1), 0, $size);
        $file = bkFile($data, $key);

        expect(bkDecrypt($file, $key))->toBe($data);
        unlink($file);
    })->with([0, 1, 1000, 65536, 70000, 400000]);

    it('round-trips incompressible data larger than several frames', function () {
        $key = BackupCipher::parseKey(BackupCipher::generateKey());
        $data = random_bytes(300000);
        $file = bkFile($data, $key);

        expect(bkDecrypt($file, $key))->toBe($data);
        unlink($file);
    });

    it('never stores readable plaintext and uses a fresh prefix per file', function () {
        $file = bkFile(str_repeat('RAHASIA-PELANGGAN ', 5000));
        $other = bkFile(str_repeat('RAHASIA-PELANGGAN ', 5000));

        expect(file_get_contents($file))->not->toContain('RAHASIA')->and(substr(file_get_contents($file), 4, 8))->not->toBe(substr(file_get_contents($other), 4, 8))
            ->and(substr(file_get_contents($file), 0, 4))->toBe('VBK1');
        array_map('unlink', [$file, $other]);
    });

    it('rejects a wrong key', function () {
        $file = bkFile('data');

        expect(fn () => bkDecrypt($file, BackupCipher::parseKey(BackupCipher::generateKey())))->toThrow(RuntimeException::class, 'Dekripsi gagal');
        unlink($file);
    });

    it('detects a single flipped bit anywhere after the header', function () {
        $key = BackupCipher::parseKey(config('backup.encryption_key'));
        $file = bkFile(random_bytes(150000), $key);
        $size = filesize($file);

        foreach ([20, intdiv($size, 2), $size - 5] as $offset) {
            $bytes = file_get_contents($file);
            $bytes[$offset] = chr(ord($bytes[$offset]) ^ 1);
            file_put_contents($file . '.x', $bytes);

            expect(fn () => bkDecrypt($file . '.x', $key))->toThrow(RuntimeException::class);
        }
        array_map('unlink', [$file, $file . '.x']);
    });

    it('detects a truncated upload, including a cut exactly at a frame boundary', function () {
        $key = BackupCipher::parseKey(config('backup.encryption_key'));
        $file = bkFile(random_bytes(200000), $key);
        $bytes = file_get_contents($file);

        // Potong tepat setelah frame data pertama (4 header + 12 magic/prefix + 4 panjang + 65536 + 16 tag).
        $boundary = 12 + 4 + 65536 + 16;
        foreach ([$boundary, strlen($bytes) - 1, strlen($bytes) - 30, 12, 8] as $cut) {
            file_put_contents($file . '.x', substr($bytes, 0, $cut));

            expect(fn () => bkDecrypt($file . '.x', $key))->toThrow(RuntimeException::class);
        }
        array_map('unlink', [$file, $file . '.x']);
    });

    it('detects removed or reordered frames', function () {
        $key = BackupCipher::parseKey(config('backup.encryption_key'));
        $file = bkFile(random_bytes(200000), $key);
        $bytes = file_get_contents($file);
        $frame = 4 + 65536 + 16;
        $head = substr($bytes, 0, 12);
        $f1 = substr($bytes, 12, $frame);
        $f2 = substr($bytes, 12 + $frame, $frame);
        $rest = substr($bytes, 12 + 2 * $frame);

        foreach ([$head . $f2 . $f1 . $rest, $head . $f2 . $rest, $head . $f1 . $f1 . $rest] as $variant) {
            file_put_contents($file . '.x', $variant);
            expect(fn () => bkDecrypt($file . '.x', $key))->toThrow(RuntimeException::class);
        }
        array_map('unlink', [$file, $file . '.x']);
    });

    it('rejects files that are not Velora backups', function () {
        $path = tempnam(sys_get_temp_dir(), 'bk');
        file_put_contents($path, "SELECT 1;\n");

        expect(fn () => bkDecrypt($path, BackupCipher::parseKey(config('backup.encryption_key'))))->toThrow(RuntimeException::class, 'Bukan berkas backup');
        unlink($path);
    });

    it('validates the key format', function () {
        foreach ([null, '', 'bukan-base64!!', base64_encode('pendek'), base64_encode(random_bytes(33))] as $bad) {
            expect(fn () => BackupCipher::parseKey($bad))->toThrow(RuntimeException::class, 'BACKUP_ENCRYPTION_KEY');
        }
        expect(strlen(BackupCipher::parseKey(BackupCipher::generateKey())))->toBe(32)->and(BackupCipher::generateKey())->not->toBe(BackupCipher::generateKey());
    });
});

describe('backup:run', function () {
    it('writes an encrypted file, a manifest and a history record to the backup disk', function () {
        $service = bkDump("INSERT INTO t VALUES ('PELANGGAN-RAHASIA');\n");
        $run = $service->run();

        Storage::disk('bk')->assertExists("backups/{$run->file}");
        Storage::disk('bk')->assertExists("backups/{$run->file}.json");
        $raw = Storage::disk('bk')->get("backups/{$run->file}");
        $manifest = json_decode(Storage::disk('bk')->get("backups/{$run->file}.json"), true);

        expect($run->status)->toBe('succeeded')->and($run->size_bytes)->toBe(strlen($raw))->and($run->sha256)->toBe(hash('sha256', $raw))
            ->and($raw)->not->toContain('PELANGGAN-RAHASIA')->and($manifest['sha256'])->toBe($run->sha256)
            ->and($manifest['tables'])->toContain('users')->and($manifest['tables'])->not->toContain('backup_runs');
    });

    it('produces a file that decrypts back to exactly what the dump emitted', function () {
        $run = bkDump("INSERT INTO t VALUES (42);\n")->run();
        $local = tempnam(sys_get_temp_dir(), 'bk');
        file_put_contents($local, Storage::disk('bk')->get("backups/{$run->file}"));

        expect(bkDecrypt($local, BackupCipher::parseKey(config('backup.encryption_key'))))->toBe("-- MySQL dump\nINSERT INTO t VALUES (42);\n-- Dump completed on 2026-10-12\n");
        unlink($local);
    });

    it('fails and keeps nothing at the destination when the dump is incomplete', function () {
        expect(fn () => bkDump('x', complete: false)->run())->toThrow(RuntimeException::class, 'Dump tidak lengkap');

        $run = BackupRun::sole();
        expect($run->status)->toBe('failed')->and($run->error)->toContain('tidak lengkap')->and(Storage::disk('bk')->allFiles())->toBe([]);
    });

    it('fails and records the error when mysqldump itself fails', function () {
        $service = new BackupService(function () { throw new RuntimeException('mysqldump gagal: Access denied'); });

        expect(fn () => $service->run())->toThrow(RuntimeException::class, 'Access denied');
        expect(BackupRun::sole()->status)->toBe('failed')->and(Storage::disk('bk')->allFiles())->toBe([]);
    });

    it('refuses to run without a valid encryption key, before touching anything', function () {
        config(['backup.encryption_key' => null]);

        expect(fn () => bkDump()->run())->toThrow(RuntimeException::class, 'BACKUP_ENCRYPTION_KEY');
        expect(BackupRun::count())->toBe(0)->and(Storage::disk('bk')->allFiles())->toBe([]);
    });

    it('leaves no temporary file behind, on success or failure', function () {
        bkDump()->run();
        try { bkDump('x', complete: false)->run(); } catch (RuntimeException) {}

        expect(glob(storage_path('app/backup-tmp/velora-*')))->toBe([]);
    });

    it('the command reports success and failure through its exit code', function () {
        app()->instance(BackupService::class, bkDump());
        $this->artisan('backup:run')->expectsOutputToContain('Backup selesai')->assertSuccessful();

        app()->instance(BackupService::class, bkDump('x', complete: false));
        $this->artisan('backup:run')->expectsOutputToContain('Backup gagal')->assertFailed();
    });
});

describe('retention', function () {
    function bkSeed(int $daysAgo, int $n = 0): string
    {
        $name = 'velora-' . now()->subDays($daysAgo)->setTime(1, 30, $n)->format('Ymd-His') . '.vbk';
        Storage::disk('bk')->put("backups/{$name}", 'x');
        Storage::disk('bk')->put("backups/{$name}.json", '{}');

        return $name;
    }

    it('deletes backups (and manifests) older than the retention period and keeps the rest', function () {
        $old = bkSeed(20);
        $older = bkSeed(40);
        $recent = bkSeed(3);
        $newest = bkSeed(0);

        $deleted = (new BackupService())->prune();

        expect($deleted)->toEqualCanonicalizing([$old, $older]);
        Storage::disk('bk')->assertMissing("backups/{$old}");
        Storage::disk('bk')->assertMissing("backups/{$old}.json");
        Storage::disk('bk')->assertExists("backups/{$recent}");
        Storage::disk('bk')->assertExists("backups/{$newest}.json");
    });

    it('never deletes the newest backup even when everything is old', function () {
        $a = bkSeed(50);
        bkSeed(60);

        (new BackupService())->prune();

        Storage::disk('bk')->assertExists("backups/{$a}");
    });

    it('ignores files that are not backups', function () {
        Storage::disk('bk')->put('backups/catatan.txt', 'x');
        Storage::disk('bk')->put('backups/velora-bukan-nama.vbk', 'x');
        bkSeed(0);

        expect((new BackupService())->prune())->toBe([]);
        Storage::disk('bk')->assertExists('backups/catatan.txt');
    });

    it('caps retention at 30 days no matter what the configuration says (DEC-15)', function () {
        config(['backup.retention_days' => 365]);
        expect((new BackupService())->retentionDays())->toBe(30);

        config(['backup.retention_days' => 0]);
        expect((new BackupService())->retentionDays())->toBe(1);
    });

    it('a successful run prunes expired backups', function () {
        $old = bkSeed(25);

        bkDump()->run();

        Storage::disk('bk')->assertMissing("backups/{$old}");
    });
});

describe('restore test safety', function () {
    it('refuses a scratch database that could overwrite real data', function (?string $name) {
        config(['backup.verify_database' => $name]);

        expect(fn () => (new BackupService())->scratchDatabase())->toThrow(RuntimeException::class, 'BACKUP_VERIFY_DATABASE');
    })->with([null, '', 'veloradigital', 'users', 'db; DROP DATABASE x', 'tanpa-underscore-restore', 'restore check']);

    it('refuses the live database name even if it contains "restore"', function () {
        config(['database.connections.' . config('database.default') . '.database' => 'app_restore', 'backup.verify_database' => 'app_restore']);

        expect(fn () => (new BackupService())->scratchDatabase())->toThrow(RuntimeException::class);
    });

    it('accepts a dedicated restore-check database', function () {
        expect((new BackupService())->scratchDatabase())->toBe('velora_restore_check');
    });

    it('refuses to verify when there is no backup, or the named backup does not exist', function () {
        expect(fn () => (new BackupService())->verify())->toThrow(RuntimeException::class, 'Belum ada backup');
        expect(fn () => (new BackupService())->verify('velora-20260101-000000.vbk'))->toThrow(RuntimeException::class, 'tidak ditemukan');
    });

    it('rejects a corrupted copy at the destination before restoring anything (checksum)', function () {
        $run = bkDump()->run();
        Storage::disk('bk')->put("backups/{$run->file}", 'diubah-di-tujuan');

        expect(fn () => (new BackupService())->verify($run->file))->toThrow(RuntimeException::class, 'Checksum');
    });
});

describe('backup:status', function () {
    function bkRun(string $status, int $hoursAgo, ?int $verifiedHoursAgo = null, bool $ok = true): void
    {
        BackupRun::create([
            'file' => 'velora-x.vbk', 'disk' => 'bk', 'status' => $status, 'started_at' => now()->subHours($hoursAgo), 'finished_at' => now()->subHours($hoursAgo),
            'verified_at' => $verifiedHoursAgo === null ? null : now()->subHours($verifiedHoursAgo), 'verify_result' => $verifiedHoursAgo === null ? null : ['ok' => $ok],
        ]);
    }

    it('is healthy with a recent backup and a recently tested restore', function () {
        bkRun('succeeded', 3, 24);

        $this->artisan('backup:status')->expectsOutputToContain('Backup sehat')->assertSuccessful();
    });

    it('fails when there has never been a successful backup', function () {
        bkRun('failed', 1);

        $this->artisan('backup:status')->expectsOutputToContain('Belum pernah ada backup yang sukses')->assertFailed();
    });

    it('fails when the last successful backup is too old', function () {
        bkRun('succeeded', 40, 41);

        $this->artisan('backup:status')->expectsOutputToContain('Backup sukses terakhir')->assertFailed();
    });

    it('fails when the restore was never tested, or only a failed test exists, or the test is stale', function () {
        bkRun('succeeded', 2);
        $this->artisan('backup:status')->expectsOutputToContain('Restore belum pernah teruji')->assertFailed();

        BackupRun::query()->update(['verified_at' => now(), 'verify_result' => ['ok' => false]]);
        $this->artisan('backup:status')->expectsOutputToContain('Restore belum pernah teruji')->assertFailed();

        BackupRun::query()->update(['verified_at' => now()->subDays(10), 'verify_result' => ['ok' => true]]);
        $this->artisan('backup:status')->expectsOutputToContain('Uji restore terakhir')->assertFailed();
    });
});

describe('backup:key and schedule', function () {
    it('prints a usable key without writing it anywhere', function () {
        $this->artisan('backup:key')->expectsOutputToContain('BACKUP_ENCRYPTION_KEY=')->assertSuccessful();
    });

    it('schedules a daily backup and a weekly restore test', function () {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());

        expect($events->first(fn ($e) => str_contains($e->command, 'backup:run'))->expression)->toBe('30 1 * * *')
            ->and($events->first(fn ($e) => str_contains($e->command, 'backup:verify'))->expression)->toBe('30 4 * * 0');
    });

    it('ships a local-only disk by default and an S3 disk when a bucket is configured', function () {
        expect(config('filesystems.disks.backup.driver'))->toBe('local');
    });
});

describe('backup:decrypt (disaster recovery)', function () {
    it('turns a backup into the original SQL and refuses to overwrite', function () {
        $run = bkDump("INSERT INTO t VALUES (7);\n")->run();
        $enc = tempnam(sys_get_temp_dir(), 'bk');
        file_put_contents($enc, Storage::disk('bk')->get("backups/{$run->file}"));
        $sql = $enc . '.sql';

        $this->artisan('backup:decrypt', ['file' => $enc, 'output' => $sql])->expectsOutputToContain('Selesai')->assertSuccessful();
        expect(file_get_contents($sql))->toContain('INSERT INTO t VALUES (7);')->toContain('Dump completed');

        $this->artisan('backup:decrypt', ['file' => $enc, 'output' => $sql])->expectsOutputToContain('tidak ditimpa')->assertFailed();
        array_map('unlink', [$enc, $sql]);
    });

    it('leaves no partial SQL when the key is wrong or the file is damaged', function () {
        $run = bkDump()->run();
        $enc = tempnam(sys_get_temp_dir(), 'bk');
        file_put_contents($enc, Storage::disk('bk')->get("backups/{$run->file}"));
        $sql = $enc . '.sql';

        config(['backup.encryption_key' => BackupCipher::generateKey()]);
        $this->artisan('backup:decrypt', ['file' => $enc, 'output' => $sql])->expectsOutputToContain('Gagal')->assertFailed();

        expect(file_exists($sql))->toBeFalse();
        unlink($enc);
    });
});
