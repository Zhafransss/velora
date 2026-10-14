<?php

use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Portfolios\Pages\CreatePortfolio;
use App\Models\Article;
use App\Models\Portfolio;
use App\Models\User;
use App\Support\Media;
use Filament\Facades\Filament;
use Filament\Forms\Components\RichEditor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/** Konfigurasi R2 palsu (tanpa jaringan); `fake` mengganti disk dengan folder lokal sementara. */
function useR2(bool $fake = true, bool $active = true): void
{
    config([
        'filesystems.disks.r2.key' => 'test-key',
        'filesystems.disks.r2.secret' => 'test-secret',
        'filesystems.disks.r2.bucket' => 'velora-media',
        'filesystems.disks.r2.endpoint' => 'https://account.r2.cloudflarestorage.com',
        'filesystems.disks.r2.url' => 'https://media.test',
        'filesystems.media_disk' => $active ? 'r2' : 'public',
    ]);
    Storage::forgetDisk('r2');

    if ($fake) {
        Storage::fake('r2', ['url' => 'https://media.test']);
    }
}

/** Mirip foto (gradasi halus): besar sebagai PNG, jauh lebih kecil sebagai WebP. Di-cache karena dibuat per piksel. */
function mediaPng(): string
{
    static $png;

    return $png ??= (function () {
        $im = imagecreatetruecolor(2000, 1000);

        for ($y = 0; $y < 1000; $y++) {
            for ($x = 0; $x < 2000; $x++) {
                $r = (int) (127 + 127 * sin($x / 41 + $y / 87));
                $g = (int) (127 + 127 * cos($y / 59));
                $b = (int) (127 + 127 * sin(($x - $y) / 67));
                imagesetpixel($im, $x, $y, ($r << 16) | ($g << 8) | $b);
            }
        }

        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    })();
}

function mediaArticle(string $thumbnail): int
{
    return DB::table('articles')->insertGetId(['title' => 'A', 'slug' => 'a-' . uniqid(), 'content' => '<p>x</p>',
        'thumbnail' => $thumbnail, 'is_published' => true, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
}

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});

// ---- Konfigurasi ----

it('never sends a public-read ACL to R2, which rejects it', function () {
    // R2 tidak mendukung ACL per objek dan menolak 'public-read' (NotImplemented); Filament/Laravel mengirimnya untuk
    // visibility 'public'. ACL eksplisit di opsi disk mengalahkan visibility pada setiap upload.
    expect(config('filesystems.disks.r2.options.ACL'))->toBe('private')
        ->and(config('filesystems.disks.r2.region'))->toBe('auto')
        ->and(config('filesystems.disks.r2.throw'))->toBeTrue();
});

it('defaults to the local public disk and keeps request-relative URLs', function () {
    // phpunit.xml memaksa ini walau .env lokal memakai MEDIA_DISK=r2: tes tidak boleh menulis ke bucket sungguhan.
    expect(Media::disk())->toBe('public')
        ->and(config('filesystems.disks.r2.key'))->toBeEmpty()
        ->and(Media::url('articles/a.webp'))->toBe(asset('storage/articles/a.webp'))
        ->and(Media::url(null, 'fallback.jpg'))->toBe('fallback.jpg');
});

// ---- Upload admin ke R2 ----

it('sends admin uploads to R2 when MEDIA_DISK=r2', function () {
    useR2();
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->platformAdmin()->create());

    Livewire::test(CreateArticle::class)
        ->fillForm([
            'title' => 'Artikel R2', 'slug' => 'artikel-r2', 'content' => '<p>Isi</p>', 'is_published' => true,
            'thumbnail' => UploadedFile::fake()->createWithContent('foto.png', mediaPng()),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $path = Article::where('slug', 'artikel-r2')->value('thumbnail');

    expect($path)->toStartWith('articles/')->toEndWith('-w1600.webp')
        ->and(Storage::disk('r2')->exists($path))->toBeTrue()
        ->and(Storage::disk('r2')->files('articles'))->toBe([$path]) // file mentah tidak ikut terkirim
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('sends portfolio cover and screenshots to R2', function () {
    useR2();
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->platformAdmin()->create());
    $category = \App\Models\Category::create(['name' => 'Web', 'slug' => 'web']);

    Livewire::test(CreatePortfolio::class)
        ->fillForm([
            'title' => 'Porto R2', 'slug' => 'porto-r2', 'category_id' => $category->id, 'is_active' => true,
            'cover_image' => UploadedFile::fake()->createWithContent('cover.png', mediaPng()),
            'images' => [['image_path' => [UploadedFile::fake()->createWithContent('shot.png', mediaPng())], 'is_active' => true]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $portfolio = Portfolio::where('slug', 'porto-r2')->firstOrFail();

    expect(Storage::disk('r2')->exists($portfolio->cover_image))->toBeTrue()
        ->and(Storage::disk('r2')->exists($portfolio->images()->value('image_path')))->toBeTrue()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('stores rich-editor images in the article body on the media disk as public files', function () {
    useR2();
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->platformAdmin()->create());

    Livewire::test(CreateArticle::class)->assertFormFieldExists('content', fn (RichEditor $field) => $field->getFileAttachmentsDiskName() === 'r2'
        && $field->getFileAttachmentsVisibility() === 'public');
});

// ---- Halaman publik ----

it('serves images from the R2 public URL on public pages', function () {
    useR2(fake: false);
    mediaArticle('articles/foto-w1600.webp');

    $this->get('/articles')->assertOk()->assertSee('https://media.test/articles/foto-w1600.webp', false)
        ->assertDontSee('/storage/articles/foto-w1600.webp', false);
});

// ---- media:sync ----

it('copies referenced images to R2 with the same paths, skips what is already there, and reports missing files', function () {
    useR2(active: false);
    Storage::disk('public')->put('articles/a.webp', 'aaa');
    Storage::disk('public')->put('services/orphan.png', 'tidak dipakai');
    mediaArticle('articles/a.webp');
    mediaArticle('articles/hilang.webp');

    $this->artisan('media:sync', ['--to' => 'r2', '--dry-run' => true])->assertSuccessful();
    expect(Storage::disk('r2')->allFiles())->toBe([]);

    $this->artisan('media:sync', ['--to' => 'r2'])
        ->expectsOutputToContain('tidak ada di public: articles/hilang.webp')
        ->assertSuccessful();

    expect(Storage::disk('r2')->get('articles/a.webp'))->toBe('aaa')
        ->and(Storage::disk('r2')->exists('services/orphan.png'))->toBeFalse() // hanya yang dipakai database
        ->and(DB::table('audit_logs')->where('action', 'system.media_synced')->count())->toBe(1);

    // Ulang: tidak ada yang disalin lagi.
    $this->artisan('media:sync', ['--to' => 'r2'])->expectsOutputToContain('Disalin 0 file')->assertSuccessful();

    $this->artisan('media:sync', ['--to' => 'r2', '--all' => true])->assertSuccessful();
    expect(Storage::disk('r2')->exists('services/orphan.png'))->toBeTrue();
});

it('refuses to sync to R2 before credentials are filled in', function () {
    config(['filesystems.disks.r2.key' => null, 'filesystems.disks.r2.bucket' => null]);

    $this->artisan('media:sync', ['--to' => 'r2'])
        ->expectsOutputToContain('Disk r2 belum lengkap')
        ->assertFailed();
});

// ---- images:optimize di R2 ----

it('optimizes images directly on R2 without touching the local mirror', function () {
    useR2();
    Storage::disk('r2')->put('articles/big.png', mediaPng());
    $id = mediaArticle('articles/big.png');
    $mirror = storage_path('framework/testing/mirror-' . uniqid());
    File::ensureDirectoryExists($mirror);

    try {
        $this->artisan('images:optimize', ['--mirror' => $mirror])->assertSuccessful();

        expect(DB::table('articles')->where('id', $id)->value('thumbnail'))->toBe('articles/big-w1600.webp')
            ->and(Storage::disk('r2')->exists('articles/big-w1600.webp'))->toBeTrue()
            ->and(Storage::disk('r2')->exists('articles/big.png'))->toBeTrue()
            ->and(File::allFiles($mirror))->toBe([]);
    } finally {
        File::deleteDirectory($mirror);
    }
});

// ---- media:check ----

it('checks R2 end to end and removes the probe file', function () {
    useR2();
    config(['app.url' => 'https://veloradigitalworks.com']);
    Http::fake(fn ($request) => Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), 200,
        ['Access-Control-Allow-Origin' => $request->header('Origin')[0] ?? '']));

    $this->artisan('media:check')->expectsOutputToContain('Disk r2 siap dipakai.')->assertSuccessful();

    expect(Storage::disk('r2')->allFiles())->toBe([]);
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://media.test/healthcheck/')
        && $request->header('Origin') === ['https://veloradigitalworks.com']);
});

it('fails the check when the bucket is not public or CORS blocks the admin', function () {
    useR2();
    Http::fake(['*' => Http::response('Unauthorized', 401)]);

    $this->artisan('media:check')
        ->expectsOutputToContain('aktifkan akses publik bucket')
        ->expectsOutputToContain('belum diizinkan')
        ->assertFailed();

    expect(Storage::disk('r2')->allFiles())->toBe([]);
});
