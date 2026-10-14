<?php

namespace App\Filament\Resources\Articles\Schemas;

use App\Filament\Forms\OptimizedImageUpload;
use App\Support\Media;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ArticleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Artikel')
                    ->schema([
                        TextInput::make('title')
                            ->label('Judul')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn($state, callable $set) => $set('slug', Str::slug($state))),

                        TextInput::make('slug')
    ->label('Slug')
    ->disabled()
    ->dehydrated() // tetap disimpan ke database
    ->required()
    ->maxLength(255)
    ->unique(ignoreRecord: true),

                        TextInput::make('category')
                            ->label('Kategori')
                            ->placeholder('Tips Website'),

                        Textarea::make('excerpt')
                            ->label('Excerpt')
                            ->rows(3)
                            ->maxLength(500),

                        OptimizedImageUpload::make('thumbnail')
                            ->label('Thumbnail')
                            ->image()
                            ->directory('articles'),

                        Toggle::make('is_published')
                            ->label('Publish')
                            ->default(true)
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set, callable $get) {

                                if ($state && blank($get('published_at'))) {
                                    $set('published_at', now());
                                }

                                if (!$state) {
                                    $set('published_at', null);
                                }
                            }),

                        DatePicker::make('published_at')
                            ->label('Tanggal Publish')
                            ->seconds(false)
                            ->default(now()),
                    ])
                    ->columns(2),

                Section::make('Konten')
                    ->schema([
                        RichEditor::make('content')
                            ->label('Isi Artikel')
                            // Gambar di dalam isi artikel ikut ke disk media (R2); bawaan Filament = disk 'local' (privat)
                            // sehingga gambar tidak tampil di halaman publik yang mencetak HTML apa adanya.
                            ->fileAttachmentsDisk(Media::disk())
                            ->fileAttachmentsDirectory('articles/content')
                            ->fileAttachmentsVisibility('public')
                            ->required()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}