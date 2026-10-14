<?php

namespace App\Filament\Resources\Accounts;

use App\Models\User;
use App\Services\Admin\WorkspaceAccountException;
use App\Services\Admin\WorkspaceAccountService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Throwable;

/** Aksi akun yang dipakai bersama oleh tabel Akun dan halaman edit akun. */
class AccountActions
{
    public static function invite(): Action
    {
        return Action::make('sendInvitation')
            ->label('Kirim ulang undangan')
            ->icon('heroicon-o-envelope')
            // Hanya akun yang belum aktivasi (email belum terverifikasi); akun aktif memakai "Lupa password" sendiri.
            ->visible(fn (User $record) => ! $record->isDisabled() && $record->email_verified_at === null)
            ->requiresConfirmation()
            ->modalDescription(fn (User $record) => "Email berisi tautan untuk membuat password (berlaku 72 jam) dikirim ke {$record->email}.")
            ->action(fn (User $record, WorkspaceAccountService $service) => self::sendInvitation($service, $record));
    }

    public static function disable(): Action
    {
        return Action::make('disableAccount')
            ->label('Nonaktifkan')
            ->icon('heroicon-o-no-symbol')
            ->color('warning')
            ->visible(fn (User $record) => ! $record->isDisabled())
            ->requiresConfirmation()
            ->modalDescription('Akun tidak bisa login dan sesi yang sedang aktif langsung berakhir. Data dan keanggotaan tetap ada; akun bisa diaktifkan kembali.')
            ->schema([Textarea::make('note')->label('Alasan')->rows(2)->maxLength(500)])
            ->action(function (array $data, User $record, WorkspaceAccountService $service) {
                self::run(fn () => $service->disable($record, $data['note'] ?? null), 'Akun dinonaktifkan');
            });
    }

    public static function enable(): Action
    {
        return Action::make('enableAccount')
            ->label('Aktifkan kembali')
            ->icon('heroicon-o-play')
            ->color('success')
            ->visible(fn (User $record) => $record->isDisabled())
            ->requiresConfirmation()
            ->schema([Textarea::make('note')->label('Catatan')->rows(2)->maxLength(500)])
            ->action(function (array $data, User $record, WorkspaceAccountService $service) {
                self::run(fn () => $service->enable($record, $data['note'] ?? null), 'Akun diaktifkan kembali');
            });
    }

    /** @param  Closure(): string|null  $redirectTo  dipakai halaman edit (rekaman sudah tidak ada setelah dihapus) */
    public static function delete(?Closure $redirectTo = null): Action
    {
        return Action::make('deleteAccount')
            ->label('Hapus permanen')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Hapus akun permanen')
            ->modalDescription('Akun dan keanggotaannya dihapus dan tidak bisa dikembalikan. Riwayat audit tetap ada. Bila hanya ingin memblokir akses, pilih Nonaktifkan.')
            ->schema([Textarea::make('note')->label('Alasan')->required()->rows(2)->maxLength(500)])
            ->action(function (array $data, User $record, WorkspaceAccountService $service, $livewire) use ($redirectTo) {
                $deleted = self::run(fn () => $service->delete($record, $data['note']), 'Akun dihapus');

                if ($deleted && $redirectTo) {
                    $livewire->redirect($redirectTo());
                }
            });
    }

    /** Dipakai juga setelah membuat akun. Mengembalikan true bila email terkirim. */
    public static function sendInvitation(WorkspaceAccountService $service, User $user): bool
    {
        try {
            $service->sendInvitation($user);
        } catch (WorkspaceAccountException $e) {
            Notification::make()->warning()->title('Undangan tidak dikirim')->body($e->getMessage())->send();

            return false;
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title('Email undangan gagal dikirim')
                ->body('Periksa pengaturan email server, lalu gunakan "Kirim ulang undangan".')->persistent()->send();

            return false;
        }

        Notification::make()->success()->title('Undangan dikirim')->body("Tautan buat password dikirim ke {$user->email}.")->send();

        return true;
    }

    private static function run(Closure $operation, string $success): bool
    {
        try {
            $operation();
        } catch (WorkspaceAccountException $e) {
            Notification::make()->danger()->title('Tidak bisa diproses')->body($e->getMessage())->persistent()->send();

            return false;
        }

        Notification::make()->success()->title($success)->send();

        return true;
    }
}
