<?php

namespace App\Filament\Resources\AuditLogs\Schemas;

use App\Models\AuditLog;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AuditLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $pretty = fn (?array $data) => $data ? json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '—';

        return $schema->components([
            Section::make('Tindakan')->columns(3)->components([
                TextEntry::make('created_at')->label('Waktu')->dateTime('d M Y H:i:s'),
                TextEntry::make('actor_label')->label('Pelaku'),
                TextEntry::make('ip_address')->label('IP')->placeholder('—'),
                TextEntry::make('action')->label('Tindakan')->badge(),
                TextEntry::make('tenant_label')->label('Workspace')->placeholder('—'),
                TextEntry::make('subject')->label('Objek')
                    ->state(fn (AuditLog $l) => $l->subject_type ? "{$l->subject_type} #{$l->subject_id}" : '—'),
                TextEntry::make('note')->label('Catatan')->columnSpanFull()->placeholder('—'),
            ]),
            Section::make('Perubahan')->columns(2)->components([
                TextEntry::make('before')->label('Sebelum')->state(fn (AuditLog $l) => $pretty($l->before))->fontFamily('mono')->extraAttributes(['style' => 'white-space:pre-wrap']),
                TextEntry::make('after')->label('Sesudah')->state(fn (AuditLog $l) => $pretty($l->after))->fontFamily('mono')->extraAttributes(['style' => 'white-space:pre-wrap']),
            ]),
        ]);
    }
}
