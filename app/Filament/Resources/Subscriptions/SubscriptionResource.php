<?php

namespace App\Filament\Resources\Subscriptions;

use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Subscriptions\Tables\SubscriptionsTable;
use App\Models\Subscription;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Semua langganan lintas workspace + tindakan penagihan manual (perpanjang/tangguhkan/batalkan).
 * Langganan BARU dibuat dari halaman detail Workspace agar pembayaran selalu terkait ke satu workspace yang jelas.
 */
class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'Langganan';
    protected static ?string $modelLabel = 'Langganan';
    protected static ?string $pluralModelLabel = 'Langganan';
    protected static string|\UnitEnum|null $navigationGroup = 'Platform';
    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return SubscriptionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListSubscriptions::route('/')];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
