<?php

namespace App\Filament\Resources\Services\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Service (mengacu ke Category)')
                ->columns(2)
                ->components([
                    Select::make('category_id')
                        ->label('Category (Nama Layanan)')
                        ->relationship('category', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        // 1 category hanya boleh 1 service (selaras unique DB)
                        ->unique(table: 'services', column: 'category_id', ignoreRecord: true),

                    Toggle::make('is_popular')
                        ->label('Popular')
                        ->default(false),

                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true),
                ]),

            Section::make('Hero')
                ->columns(2)
                ->components([
                    Textarea::make('hero_description')
                        ->label('Hero Description')
                        ->rows(6),

                    FileUpload::make('hero_image')
                        ->label('Hero Image')
                        ->image()
                        ->disk('public')
                        ->directory('services')
                        ->visibility('public')
                        ->imageEditor(),
                ]),

            Section::make('Packages (paket layanan)')
                ->description('Card /services menampilkan paket termurah berdasarkan min(price). Urutan paket bisa drag.')
                ->components([
                    Repeater::make('packages')
                        ->relationship('packages')
                        ->reorderable('sort_order') // ✅ auto isi sort_order via drag
                        ->defaultItems(0)
                        ->collapsed()
                        ->itemLabel(fn (array $state): ?string => $state['name'] ?? 'Paket')
                        ->components([
                            Grid::make(2)->components([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(120),

                                TextInput::make('price')
                                    ->label('Price (Rupiah)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required()
                                    ->helperText('Simpan integer rupiah. Contoh: 1500000'),
                            ]),

                            Textarea::make('description')
                                ->rows(3),

                            Toggle::make('is_active')
                                ->default(true),

                            Section::make('Package Features')
                                ->components([
                                    Repeater::make('features')
                                        ->relationship('features')
                                        ->reorderable('sort_order') // ✅ auto isi sort_order via drag
                                        ->defaultItems(0)
                                        ->collapsed()
                                        ->itemLabel(fn (array $state): ?string => $state['text'] ?? 'Fitur')
                                        ->components([
                                            TextInput::make('text')
                                                ->label('Feature')
                                                ->required()
                                                ->maxLength(255),
                                        ]),
                                ]),
                        ]),
                ]),

            Section::make('Service Features (section features di halaman detail)')
                ->components([
                    Repeater::make('serviceFeatures')
                        ->relationship('serviceFeatures')
                        ->reorderable('sort_order') // ✅ auto isi sort_order via drag
                        ->defaultItems(0)
                        ->collapsed()
                        ->itemLabel(fn (array $state): ?string => $state['title'] ?? 'Feature')
                        ->components([
                            Grid::make(2)->components([
                                TextInput::make('icon')
                                    ->label('Font Awesome Icon Class')
                                    ->placeholder('fa-solid fa-bolt')
                                    ->maxLength(120),

                                TextInput::make('title')
                                    ->required()
                                    ->maxLength(120),
                            ]),

                            Textarea::make('description')
                                ->rows(2),

                            Toggle::make('is_active')
                                ->default(true),
                        ]),
                ]),
        ]);
    }
}