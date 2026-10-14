<?php

namespace App\Filament\Forms;

use App\Services\Media\ImageOptimizer;
use App\Support\Media;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Upload gambar konten website yang otomatis diperkecil (gambar asli 6000x4000 px / 6,5 MB pernah membuat halaman
 * artikel 15,6 MB) dan disimpan di disk media (Media::disk(): lokal 'public' atau Cloudflare R2). Dua lapis:
 *  1. Browser admin memperkecil gambar sebelum diunggah (lebar maks ImageOptimizer::MAX_WIDTH), sehingga server
 *     tidak perlu men-decode foto kamera berukuran besar (batas memori PHP hosting).
 *  2. Server mengubahnya ke WebP di memori lalu menulis hasilnya saja ke disk media. Bila gagal (format/memori),
 *     file asli yang disimpan: upload tidak pernah gagal karena optimasi.
 */
class OptimizedImageUpload extends FileUpload
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->image()
            ->disk(Media::disk())
            // Untuk URL preview (url() alih-alih temporaryUrl()). Di R2 akses publik diatur di level bucket.
            ->visibility('public')
            ->imageResizeMode('contain')
            ->imageResizeTargetWidth((string) ImageOptimizer::MAX_WIDTH)
            ->imageResizeTargetHeight((string) ImageOptimizer::MAX_HEIGHT)
            ->imageResizeUpscale(false)
            ->saveUploadedFileUsing(static function (BaseFileUpload $component, TemporaryUploadedFile $file): ?string {
                $disk = $component->getDiskName();
                $name = $component->getUploadedFileNameForStorage($file);

                [$result, $webp] = app(ImageOptimizer::class)
                    ->convert(trim($component->getDirectory() . '/' . $name, '/'), (string) $file->get());

                if ($webp !== null) {
                    Storage::disk($disk)->put($result->newPath, $webp);

                    return $result->newPath;
                }

                return $file->storeAs($component->getDirectory(), $name, $disk) ?: null;
            });
    }
}
