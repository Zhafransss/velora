<?php

namespace App\Filament\Resources\Invoices\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Invoice')
                ->columns(3)
                ->components([
                    TextEntry::make('invoice_number')->label('No. Invoice')->copyable(),
                    TextEntry::make('invoice_date')->label('Tanggal')->date('d M Y'),
                    TextEntry::make('payment_method_detail.label')->label('Metode Pembayaran'),
                    TextEntry::make('customer_name')->label('Nama Pelanggan'),
                    TextEntry::make('business_name')->label('Nama Bisnis')->placeholder('-'),
                    TextEntry::make('whatsapp')->label('WhatsApp'),
                    TextEntry::make('address')->label('Alamat')->columnSpanFull(),
                ]),

            Section::make('Rincian Pembayaran')
                ->components([
                    RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->columns(3)
                        ->components([
                            TextEntry::make('description')->label('Deskripsi'),
                            TextEntry::make('qty')->label('QTY'),
                            TextEntry::make('price')->label('Harga')->money('IDR', locale: 'id'),
                        ]),
                    TextEntry::make('total')->label('Total')->money('IDR', locale: 'id')->weight('bold'),
                    TextEntry::make('notes')->label('Catatan')->placeholder('-'),
                ]),
        ]);
    }
}
