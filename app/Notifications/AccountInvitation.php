<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email undangan untuk akun workspace yang dibuat admin platform (DEC-20). Penerima membuat password sendiri lewat
 * tautan bertoken (broker `invites`); admin tidak pernah mengetahui password pelanggan.
 *
 * Sengaja tidak di-antrekan: admin langsung tahu bila pengiriman gagal dan bisa mengirim ulang.
 */
class AccountInvitation extends Notification
{
    public const ROLE_LABELS = ['owner' => 'Pemilik (owner)', 'admin' => 'Admin', 'viewer' => 'Viewer (hanya lihat)'];

    public function __construct(
        public readonly string $token,
        public readonly Tenant $tenant,
        public readonly string $role,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url(route('invitation.show', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()], false));
        $hours = intdiv((int) config('auth.passwords.invites.expire'), 60);

        return (new MailMessage())
            ->subject('Akun Velora AI Anda untuk ' . $this->tenant->name)
            ->greeting('Halo, ' . $notifiable->name . '!')
            ->line("Anda ditambahkan ke workspace **{$this->tenant->name}** di Velora AI sebagai **" . (self::ROLE_LABELS[$this->role] ?? $this->role) . '**.')
            ->line('Klik tombol di bawah untuk membuat password, lalu login ke dashboard.')
            ->action('Buat Password', $url)
            ->line("Tautan ini berlaku selama {$hours} jam. Bila kedaluwarsa, minta admin Velora mengirim ulang undangan.")
            ->line('Jika Anda tidak mengenal workspace ini, abaikan email ini.')
            ->salutation("Salam,\nTim Velora Digital");
    }
}
