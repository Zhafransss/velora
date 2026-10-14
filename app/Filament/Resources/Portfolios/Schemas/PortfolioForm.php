<?php

namespace App\Filament\Resources\Portfolios\Schemas;

use App\Filament\Forms\OptimizedImageUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PortfolioForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('category_id')
                ->label('Category')
                ->relationship('category', 'name')
                ->searchable()
                ->preload()
                ->required(),

            TextInput::make('title')
                ->required()
                ->live(debounce: 500)
                ->afterStateUpdated(fn(Set $set, ?string $state) => $set('slug', Str::slug($state ?? ''))),

            TextInput::make('slug')
                ->required()
                ->unique(ignoreRecord: true),

            OptimizedImageUpload::make('cover_image')
                ->label('Cover Image')
                ->directory('portfolio_cover')
                ->image()
                ->required(),

            Toggle::make('is_active')
                ->default(true),

            Repeater::make('images')
                ->label('Portfolio Images')
                ->relationship('images')
                ->schema([
                    OptimizedImageUpload::make('image_path')
                        ->label('Image')
                        ->directory('portfolio_images')
                        ->image()
                        ->required(),

                    Toggle::make('is_active')->default(true),
                ])
                ->orderColumn('sort_order')
                ->reorderable()
                ->collapsed()
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}