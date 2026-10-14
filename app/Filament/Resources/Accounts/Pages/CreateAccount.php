<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountActions;
use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Tenant;
use App\Services\Admin\WorkspaceAccountException;
use App\Services\Admin\WorkspaceAccountService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAccount extends CreateRecord
{
    protected static string $resource = AccountResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(WorkspaceAccountService::class)->create(
                Tenant::findOrFail($data['tenant_id']), $data['name'], $data['email'], $data['role'],
            );
        } catch (WorkspaceAccountException $e) {
            Notification::make()->danger()->title('Akun tidak dibuat')->body($e->getMessage())->send();
            $this->halt();
        }
    }

    protected function afterCreate(): void
    {
        AccountActions::sendInvitation(app(WorkspaceAccountService::class), $this->record);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()->success()->title('Akun dibuat');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
