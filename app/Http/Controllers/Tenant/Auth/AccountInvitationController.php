<?php

namespace App\Http\Controllers\Tenant\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Penerima undangan admin platform (DEC-20) membuat password sendiri. Memakai broker `invites` (tabel dan masa berlaku
 * sendiri), jadi token reset password biasa tidak berlaku di sini dan sebaliknya.
 */
class AccountInvitationController extends Controller
{
    public function show(Request $request, string $token)
    {
        return view('tenant.auth.accept-invitation', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ], PasswordMessages::MESSAGES);

        $invalid = fn () => back()->withInput($request->only('email'))
            ->withErrors(['email' => 'Tautan undangan tidak valid atau sudah kedaluwarsa. Minta admin Velora mengirim ulang undangan.']);

        $user = User::where('email', Str::lower(trim($request->input('email'))))->first();

        if ($user === null || $user->is_platform_admin || $user->isDisabled()) {
            return $invalid();
        }

        $status = Password::broker('invites')->reset(
            ['email' => $user->email] + $request->only('password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)]);

                // Tautan dibuka dari kotak masuk penerima, jadi alamat emailnya terbukti.
                $user->email_verified_at ??= now();
                $user->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return $invalid();
        }

        return redirect()->route('login')->with('status', 'Password berhasil dibuat. Silakan login.');
    }
}
