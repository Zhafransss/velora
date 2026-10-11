<?php

namespace App\Filament\Resources\ContactMessages\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ContactMessageInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Kontak')
                    ->description('Data pengirim dari form kontak website.')
                    ->schema([
                        TextEntry::make('name')
                            ->label('Nama'),

                        TextEntry::make('phone')
                            ->label('Nomor WhatsApp'),

                        TextEntry::make('email')
                            ->label('Email')
                            ->placeholder('Tidak diisi'),

                        TextEntry::make('type')
                            ->label('Jenis Website'),

                        TextEntry::make('created_at')
                            ->label('Tanggal Masuk')
                            ->dateTime('d M Y • H:i'),

                        TextEntry::make('ip_address')
                            ->label('IP Address')
                            ->placeholder('-'),
                    ])
                    ->columns(2),

                Section::make('Isi Pesan')
                    ->schema([
                        TextEntry::make('message')
                            ->label('Pesan')
                            ->placeholder('Tidak ada pesan')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}