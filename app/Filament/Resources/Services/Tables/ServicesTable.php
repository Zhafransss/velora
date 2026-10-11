<?php

namespace App\Filament\Resources\Services\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // ✅ drag & drop urutan service (otomatis isi sort_order)
            ->reorderable('sort_order')

            ->columns([
                TextColumn::make('category.name')
                    ->label('Category')
                    ->searchable()
                    ->sortable(),

                ImageColumn::make('hero_image')
                    ->label('Hero Image')
                    ->disk('public')
                    ->visibility('public'),

                IconColumn::make('is_popular')
                    ->label('Popular')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
            ])

            ->filters([
                TernaryFilter::make('is_popular')
                    ->label('Popular'),

                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])

            ->recordActions([
                EditAction::make(),
            ])

            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}