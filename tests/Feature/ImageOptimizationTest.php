<?php

use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Models\Article;
use App\Models\User;
use App\Services\Media\ImageOptimizer;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Gambar mirip foto: gradasi halus, besar sebagai PNG tetapi kecil sebagai WebP (seperti foto/screenshot asli).
 * Di-cache per ukuran karena pembuatan per piksel lambat.
 */
function imgPhotoPng(int $w, int $h): string
{
    static $cache = [];

    return $cache["{$w}x{$h}"] ??= (function () use ($w, $h) {
        $im = imagecreatetruecolor($w, $h);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $r = (int) (127 + 127 * sin($x / 37 + $y / 91));
                $g = (int) (127 + 127 * sin($y / 53));
                $b = (int) (127 + 127 * cos(($x + $y) / 71));
                imagesetpixel($im, $x, $y, ($r << 16) | ($g << 8) | $b);
            }
        }

        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    })();
}

/** Gambar transparan berpola blok (untuk uji kanal alfa). */
function imgNoisyPng(int $w, int $h, bool $alpha = false): string
{
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, false);
    imagesavealpha($im, true);

    for ($y = 0; $y < $h; $y += 4) {
        for ($x = 0; $x < $w; $x += 4) {
            $color = $alpha
                ? imagecolorallocatealpha($im, random_int(0, 255), random_int(0, 255), random_int(0, 255), 127)
                : imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255));
            imagefilledrectangle($im, $x, $y, $x + 3, $y + 3, $color);
        }
    }

    ob_start();
    imagepng($im);

    return (string) ob_get_clean();
}

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});

// ---- ImageOptimizer ----

it('shrinks a large image to a 1600px-wide WebP and keeps the original', function () {
    Storage::disk('public')->put('articles/big.png', imgPhotoPng(2000, 1000));

    $r = app(ImageOptimizer::class)->optimize('public', 'articles/big.png');

    expect($r->wasOptimized())->toBeTrue()
        ->and($r->newPath)->toBe('articles/big-w1600.webp')
        ->and($r->newSize)->toBe([1600, 800])
        ->and($r->newBytes)->toBeLessThan($r->oldBytes)
        ->and(Storage::disk('public')->exists('articles/big.png'))->toBeTrue();

    [$w, $h, $type] = getimagesize(Storage::disk('public')->path($r->newPath));
    expect([$w, $h, $type])->toBe([1600, 800, IMAGETYPE_WEBP]);
});

it('keeps PNG transparency', function () {
    Storage::disk('public')->put('services/logo.png', imgNoisyPng(2000, 400, alpha: true));

    $r = app(ImageOptimizer::class)->optimize('public', 'services/logo.png');
    $im = imagecreatefromwebp(Storage::disk('public')->path($r->newPath));

    expect((imagecolorat($im, 10, 10) >> 24) & 0x7F)->toBeGreaterThan(100); // hampir transparan penuh
});

it('limits very tall screenshots to the WebP height limit', function () {
    Storage::disk('public')->put('portfolio_images/tall.png', imgPhotoPng(100, 17000));

    $r = app(ImageOptimizer::class)->optimize('public', 'portfolio_images/tall.png');

    expect($r->newSize[1])->toBeLessThanOrEqual(ImageOptimizer::MAX_HEIGHT);
});

it('skips images that need no work or cannot be handled', function () {
    $small = imagecreatetruecolor(800, 400);
    ob_start();
    imagewebp($small);
    Storage::disk('public')->put('a/small.webp', ob_get_clean());
    Storage::disk('public')->put('a/notes.txt', 'bukan gambar');

    $o = app(ImageOptimizer::class);

    expect($o->optimize('public', 'a/small.webp')->reason)->toBe('already_optimal')
        ->and($o->optimize('public', 'a/notes.txt')->reason)->toBe('unsupported')
        ->and($o->optimize('public', 'a/missing.png')->reason)->toBe('missing');
});

it('skips instead of crashing when PHP lacks memory to decode the image', function () {
    Storage::disk('public')->put('articles/huge.png', imgPhotoPng(2000, 1000));
    $limit = ini_get('memory_limit');
    // Sedikit di atas pemakaian riil: cukup untuk berjalan, tidak cukup untuk men-decode gambar 2000x1000.
    ini_set('memory_limit', (string) (memory_get_usage(true) + 4 * 1048576));

    try {
        $r = app(ImageOptimizer::class)->optimize('public', 'articles/huge.png');
    } finally {
        ini_set('memory_limit', $limit);
    }

    expect($r->reason)->toBe('insufficient_memory');
});

// ---- images:optimize ----

function imgArticle(string $path): int
{
    return DB::table('articles')->insertGetId(['title' => 'A', 'slug' => 'a-' . uniqid(), 'content' => '<p>x</p>', 'thumbnail' => $path,
        'is_published' => true, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
}

it('changes nothing on --dry-run', function () {
    Storage::disk('public')->put('articles/big.png', imgPhotoPng(2000, 1000));
    $id = imgArticle('articles/big.png');

    $this->artisan('images:optimize', ['--dry-run' => true, '--no-mirror' => true])->assertSuccessful();

    expect(DB::table('articles')->where('id', $id)->value('thumbnail'))->toBe('articles/big.png')
        ->and(Storage::disk('public')->exists('articles/big-w1600.webp'))->toBeFalse();
});

it('points the database to the WebP copy, mirrors it, writes a manifest, and can roll back', function () {
    Storage::disk('public')->put('articles/big.png', imgPhotoPng(2000, 1000));
    $id = imgArticle('articles/big.png');
    $mirror = storage_path('framework/testing/mirror-' . uniqid());
    File::ensureDirectoryExists($mirror);

    try {
        $this->artisan('images:optimize', ['--mirror' => $mirror])->assertSuccessful();

        expect(DB::table('articles')->where('id', $id)->value('thumbnail'))->toBe('articles/big-w1600.webp')
            ->and(Storage::disk('public')->exists('articles/big.png'))->toBeTrue() // asli tidak dihapus
            ->and(is_file("{$mirror}/articles/big-w1600.webp"))->toBeTrue();

        $manifest = basename(Storage::disk('local')->files('image-optimizations')[0]);
        $this->artisan('images:optimize', ['--rollback' => $manifest])->assertSuccessful();

        expect(DB::table('articles')->where('id', $id)->value('thumbnail'))->toBe('articles/big.png');
    } finally {
        File::deleteDirectory($mirror);
    }
});

// ---- Upload panel admin ----

it('stores admin uploads as a resized WebP and removes the raw upload', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->platformAdmin()->create());

    Livewire::test(CreateArticle::class)
        ->fillForm([
            'title' => 'Artikel Uji', 'slug' => 'artikel-uji', 'content' => '<p>Isi</p>', 'is_published' => true,
            'thumbnail' => UploadedFile::fake()->createWithContent('foto.png', imgPhotoPng(2000, 1000)),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $path = Article::where('slug', 'artikel-uji')->value('thumbnail');

    expect($path)->toEndWith('-w1600.webp')
        ->and(Storage::disk('public')->exists($path))->toBeTrue()
        ->and(Storage::disk('public')->files('articles'))->toBe([$path]); // file mentah tidak tertinggal
});

// ---- Halaman publik ----

it('loads the first article image eagerly and lazy-loads the rest', function () {
    imgArticle('articles/1.webp');
    imgArticle('articles/2.webp');
    imgArticle('articles/3.webp');

    $html = $this->get('/articles')->assertOk()->getContent();

    expect(substr_count($html, 'fetchpriority="high"'))->toBe(1)
        ->and(substr_count($html, 'loading="lazy"'))->toBeGreaterThanOrEqual(2);
});

it('loads one pinned Alpine and one Font Awesome, without the broken module imports', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect(substr_count($html, 'alpinejs@'))->toBe(1)
        ->and($html)->toContain('alpinejs@3.17.4')->toContain('integrity="sha384-')
        // Satu Font Awesome, dimuat non-blocking (preload + onload) dengan cadangan <noscript>.
        ->and(substr_count($html, 'font-awesome/6.5.1/css/all.min.css'))->toBe(2)
        ->and($html)->toMatch('#<link rel="preload" href="[^"]+font-awesome/6\.5\.1/css/all\.min\.css" as="style"[^>]+onload=#')
        ->and($html)->toMatch('#<noscript><link rel="stylesheet" href="[^"]+font-awesome/6\.5\.1#')
        ->and($html)->not->toContain('kit.fontawesome.com')
        ->and($html)->not->toContain('unpkg.com/alpinejs')
        ->and($html)->not->toContain("import Alpine from 'alpinejs'");
});

it('keeps the Google Analytics and Ads tags untouched', function () {
    // Perubahan tracking sengaja tidak dilakukan (keputusan pemilik produk, 2026-10-13).
    $this->get('/')->assertOk()
        ->assertSee('GTM-MP5HVZ44', false)
        ->assertSee('gtag/js?id=G-LLZ1VLMTLZ', false)
        ->assertSee('gtag/js?id=AW-18059024743', false);
});

it('ships identical .htaccess caching rules for public and public_html', function () {
    $public = file_get_contents(base_path('public/.htaccess'));

    expect($public)->toBe(file_get_contents(base_path('public_html/.htaccess')))
        ->and($public)->toContain('mod_expires.c')->toContain('mod_deflate.c');
});

it('versions tenant.css so it can be cached long-term', function () {
    $this->get('/login')->assertOk()->assertSee('css/tenant.css?v=', false);
});
