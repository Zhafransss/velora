<?php

namespace App\Http\Controllers\Tenant\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EmailVerificationController extends Controller
{
    public function notice(Request $request)
    {
        return $request->user()->hasVerifiedEmail()
            ? redirect()->route('app.dashboard')
            : view('tenant.auth.verify-email');
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return redirect()->route('app.dashboard');
    }

    public function send(Request $request): RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            try {
                $request->user()->sendEmailVerificationNotification();
            } catch (\Throwable $e) {
                Log::error('mail.verification.failed', ['error' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 200)]);

                return back()->with('mail_error', true);
            }
        }

        return back()->with('status', 'Tautan verifikasi baru telah dikirim.');
    }
}
