<?php

use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\Tenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function authChannel(): Channel
{
    $t = Tenant::factory()->create();
    $bot = Bot::factory()->create(['tenant_id' => $t->id]);

    return Channel::factory()->create(['tenant_id' => $t->id, 'bot_id' => $bot->id]);
}

function authRows(string $channelId, int $keys = 2, ?\DateTimeInterface $at = null): void
{
    $at ??= now();
    DB::table('wa_auth_creds')->insert(['channel_id' => $channelId, 'payload' => 'v1.k1.x.y.z', 'enc_key_id' => 'k1', 'updated_at' => $at]);
    foreach (range(1, $keys) as $i) {
        DB::table('wa_auth_keys')->insert(['channel_id' => $channelId, 'key_type' => 'session', 'key_id' => "id{$i}", 'payload' => 'v1.k1.x.y.z', 'enc_key_id' => 'k1', 'updated_at' => $at]);
    }
}

function authCount(string $channelId): array
{
    return [DB::table('wa_auth_creds')->where('channel_id', $channelId)->count(), DB::table('wa_auth_keys')->where('channel_id', $channelId)->count()];
}

describe('schema', function () {
    it('has the credential and key tables with the expected columns', function () {
        expect(Schema::hasColumns('wa_auth_creds', ['channel_id', 'payload', 'enc_key_id', 'updated_at']))->toBeTrue()
            ->and(Schema::hasColumns('wa_auth_keys', ['channel_id', 'key_type', 'key_id', 'payload', 'enc_key_id', 'updated_at']))->toBeTrue();
    });

    it('allows one credential row per channel and one row per (channel, type, id)', function () {
        authRows('chanA', 1);

        expect(fn () => DB::table('wa_auth_creds')->insert(['channel_id' => 'chanA', 'payload' => 'x', 'enc_key_id' => 'k1', 'updated_at' => now()]))
            ->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
        expect(fn () => DB::table('wa_auth_keys')->insert(['channel_id' => 'chanA', 'key_type' => 'session', 'key_id' => 'id1', 'payload' => 'x', 'enc_key_id' => 'k1', 'updated_at' => now()]))
            ->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);

        // Channel, jenis, atau id berbeda boleh sama pada kolom lainnya.
        DB::table('wa_auth_keys')->insert(['channel_id' => 'chanB', 'key_type' => 'session', 'key_id' => 'id1', 'payload' => 'x', 'enc_key_id' => 'k1', 'updated_at' => now()]);
        DB::table('wa_auth_keys')->insert(['channel_id' => 'chanA', 'key_type' => 'pre-key', 'key_id' => 'id1', 'payload' => 'x', 'enc_key_id' => 'k1', 'updated_at' => now()]);
        expect(DB::table('wa_auth_keys')->count())->toBe(3);
    });

    it('is not touched by the Laravel app models (only the connector uses these tables)', function () {
        $files = collect(glob(app_path('Models/*.php')))->filter(fn ($f) => str_contains(file_get_contents($f), "wa_auth_"));

        // Hanya Channel yang menyebutnya, untuk MENGHAPUS kredensial saat kanal dihapus.
        expect($files->map(fn ($f) => basename($f))->values()->all())->toBe(['Channel.php']);
    });
});

describe('credential lifecycle', function () {
    it('deletes the credentials of a channel when the channel is deleted, and only theirs', function () {
        $a = authChannel();
        $b = authChannel();
        authRows($a->public_id, 3);
        authRows($b->public_id, 2);

        $a->delete();

        expect(authCount($a->public_id))->toBe([0, 0])->and(authCount($b->public_id))->toBe([1, 2]);
    });

    it('does nothing special for channels without credentials', function () {
        $a = authChannel();

        $a->delete();

        expect(Channel::withoutTenancy()->count())->toBe(0)->and(DB::table('wa_auth_creds')->count())->toBe(0);
    });
});

describe('whatsapp:prune-auth', function () {
    it('removes credentials of channels that no longer exist (e.g. deleted by DB cascade)', function () {
        $kept = authChannel();
        $gone = (string) Str::ulid();
        authRows($kept->public_id, 2, now()->subDays(3));
        authRows($gone, 4, now()->subDays(3));

        $this->artisan('whatsapp:prune-auth')->expectsOutputToContain('1 channel yatim, 5 baris kredensial dihapus')->assertSuccessful();

        expect(authCount($gone))->toBe([0, 0])->and(authCount($kept->public_id))->toBe([1, 2]);
        expect(AuditLog::where('action', 'system.whatsapp_auth_pruned')->sole()->after)->toEqual(['channels' => 1, 'rows' => 5]);
    });

    it('removes credentials left behind when a tenant is deleted via cascade', function () {
        $channel = authChannel();
        authRows($channel->public_id, 2, now()->subDays(3));

        Tenant::whereKey($channel->tenant_id)->delete(); // cascade DB: tidak memicu event model

        expect(authCount($channel->public_id))->toBe([1, 2]); // yatim
        $this->artisan('whatsapp:prune-auth')->assertSuccessful();
        expect(authCount($channel->public_id))->toBe([0, 0]);
    });

    it('never touches non-ULID ids (PoC channels) or recent rows that the connector may still use', function () {
        $recentOrphan = (string) Str::ulid();
        authRows('poc_a', 2, now()->subDays(30));
        authRows($recentOrphan, 2, now()->subHours(2));

        $this->artisan('whatsapp:prune-auth')->expectsOutputToContain('0 channel yatim')->assertSuccessful();

        expect(authCount('poc_a'))->toBe([1, 2])->and(authCount($recentOrphan))->toBe([1, 2]);
    });

    it('skips an orphan when its keys were updated recently even if the credentials are old', function () {
        $id = (string) Str::ulid();
        authRows($id, 1, now()->subDays(10));
        DB::table('wa_auth_keys')->where('channel_id', $id)->update(['updated_at' => now()->subMinutes(5)]);

        $this->artisan('whatsapp:prune-auth')->assertSuccessful();

        expect(authCount($id))->toBe([1, 1]);
    });

    it('counts without deleting on --dry-run and writes no audit entry', function () {
        $gone = (string) Str::ulid();
        authRows($gone, 2, now()->subDays(3));

        $this->artisan('whatsapp:prune-auth', ['--dry-run' => true])->expectsOutputToContain('1 channel yatim, 3 baris kredensial akan dihapus')->assertSuccessful();

        expect(authCount($gone))->toBe([1, 2])->and(AuditLog::count())->toBe(0);
    });

    it('is idempotent and silent when there is nothing to do', function () {
        $this->artisan('whatsapp:prune-auth')->expectsOutputToContain('0 channel yatim')->assertSuccessful();
        $this->artisan('whatsapp:prune-auth')->assertSuccessful();

        expect(AuditLog::count())->toBe(0);
    });

    it('is scheduled weekly', function () {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'whatsapp:prune-auth'));

        expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('15 3 * * 0');
    });
});
