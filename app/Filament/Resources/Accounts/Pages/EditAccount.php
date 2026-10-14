<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountActions;
use App\Filament\Resources\Accounts\AccountResource;
use App\Services\Admin\WorkspaceAccountException;
use App\Services\Admin\WorkspaceAccountService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAccount extends EditRecord
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AccountActions::invite(),
            AccountActions::disable(),
            AccountActions::enable(),
            AccountActions::delete(redirectTo: fn () => AccountResource::getUrl('index')),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $membership = app(WorkspaceAccountService::class)->membership($this->record);

        return $data + ['tenant_id' => $membership?->tenant_id, 'role' => $membership?->role];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(WorkspaceAccountService::class)->update($record, $data['name'], $data['email'], $data['role']);
        } catch (WorkspaceAccountException $e) {
            Notification::make()->danger()->title('Perubahan tidak disimpan')->body($e->getMessage())->persistent()->send();
            $this->halt();
        }
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResource()::getUrl('index');
    }
}
