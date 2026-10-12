<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Models\AuditLog;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Waktu')->dateTime('d M Y H:i:s')->sortable(),
                TextColumn::make('actor_label')->label('Pelaku')->searchable(),
                TextColumn::make('action')->label('Tindakan')->badge()->searchable()
                    ->color(fn (string $state) => str_contains($state, 'suspend') || str_contains($state, 'cancel') ? 'danger' : 'info'),
                TextColumn::make('tenant_label')->label('Workspace')->searchable()->placeholder('—'),
                TextColumn::make('note')->label('Catatan')->limit(50)->tooltip(fn (AuditLog $l) => $l->note)->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('action')->label('Tindakan')->options(fn () => AuditLog::query()->distinct()->orderBy('action')->pluck('action', 'action')->all()),
            ])
            ->recordActions([ViewAction::make()->label('Detail')]);
    }
}
