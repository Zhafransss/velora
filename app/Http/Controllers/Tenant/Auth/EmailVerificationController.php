<?php

namespace App\Http\Controllers\Tenant\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
            $request->user()->sendEmailVerificationNotification();
        }

        return back()->with('status', 'Tautan verifikasi baru telah dikirim.');
    }
}
