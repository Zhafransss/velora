<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Lihat Invoice')
                ->icon('heroicon-o-eye')
                ->url(fn () => route('invoices.pdf', $this->record), shouldOpenInNewTab: true),
            Action::make('download')
                ->label('Unduh PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->url(fn () => route('invoices.pdf', [$this->record, 'download' => 1])),
            EditAction::make(),
        ];
    }
}
