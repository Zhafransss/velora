<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('invoice_number')->label('No. Invoice')->searchable()->sortable(),
                TextColumn::make('invoice_date')->label('Tanggal')->date('d M Y')->sortable(),
                TextColumn::make('customer_name')->label('Pelanggan')->searchable(),
                TextColumn::make('business_name')->label('Bisnis')->searchable()->placeholder('-'),
                TextColumn::make('total')->label('Total')->money('IDR', locale: 'id'),
                TextColumn::make('payment_method')
                    ->label('Pembayaran')
                    ->formatStateUsing(fn (string $state) => Invoice::PAYMENT_METHODS[$state]['label'] ?? $state)
                    ->badge(),
            ])
            ->recordActions([
                ViewAction::make()->label('Lihat'),
                Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (Invoice $record) => route('invoices.pdf', [$record, 'download' => 1])),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
