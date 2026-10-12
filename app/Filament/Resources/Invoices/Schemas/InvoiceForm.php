<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Models\Invoice;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Data Pelanggan')
                ->columns(2)
                ->components([
                    TextInput::make('customer_name')
                        ->label('Nama Pelanggan')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('business_name')
                        ->label('Nama Bisnis')
                        ->maxLength(255),

                    TextInput::make('whatsapp')
                        ->label('Nomor WhatsApp')
                        ->tel()
                        ->required()
                        ->maxLength(30),

                    DatePicker::make('invoice_date')
                        ->label('Tanggal Invoice')
                        ->default(now())
                        ->required(),

                    Textarea::make('address')
                        ->label('Alamat')
                        ->required()
                        ->rows(3)
                        ->columnSpanFull(),
                ]),

            Section::make('Rincian Pembayaran')
                ->components([
                    Repeater::make('items')
                        ->label('Item')
                        ->required()
                        ->minItems(1)
                        ->defaultItems(1)
                        ->addActionLabel('Tambah item')
                        ->columns(12)
                        ->components([
                            TextInput::make('description')
                                ->label('Deskripsi')
                                ->required()
                                ->columnSpan(6),

                            TextInput::make('qty')
                                ->label('QTY')
                                ->numeric()
                                ->minValue(1)
                                ->default(1)
                                ->required()
                                ->columnSpan(2),

                            TextInput::make('price')
                                ->label('Harga')
                                ->numeric()
                                ->minValue(0)
                                ->prefix('Rp')
                                ->required()
                                ->columnSpan(4),
                        ]),
                ]),

            Section::make('Pembayaran & Catatan')
                ->columns(2)
                ->components([
                    Select::make('payment_method')
                        ->label('Metode Pembayaran')
                        ->options(collect(Invoice::PAYMENT_METHODS)->map(fn ($m) => $m['label'])->all())
                        ->required(),

                    Textarea::make('notes')
                        ->label('Catatan')
                        ->rows(3),
                ]),
        ]);
    }
}
