<?php

namespace App\Filament\Resources\Articles\Schemas;

use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ArticleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Detail Artikel')
                    ->schema([
                        TextEntry::make('title')
                            ->label('Judul'),

                        TextEntry::make('slug')
                            ->label('Slug'),

                        TextEntry::make('category')
                            ->label('Kategori')
                            ->placeholder('-'),

                        TextEntry::make('is_published')
                            ->label('Status')
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Published' : 'Draft'),

                        TextEntry::make('published_at')
                            ->label('Tanggal Publish')
                            ->dateTime('d M Y H:i')
                            ->placeholder('-'),

                        ImageEntry::make('thumbnail')
                            ->label('Thumbnail')
                            ->disk('public')
                            ->visibility('public')
                            ->columnSpanFull(),

                        TextEntry::make('excerpt')
                            ->label('Excerpt')
                            ->placeholder('-')
                            ->columnSpanFull(),

                        TextEntry::make('content')
                            ->label('Konten')
                            ->html()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}