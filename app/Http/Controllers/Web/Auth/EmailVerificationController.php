<?php

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailVerificationController extends Controller
{
    public function notice(): Response
    {
        return Inertia::render('Auth/VerifyEmail');
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('catalog');
        }

        $request->fulfill();

        Audit::log('auth.email_verified', $request->user());

        return redirect()->route('catalog')
            ->with('success', 'E-mail confirmado! Bem-vindo ao Portal.');
    }

    public function resend(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('catalog');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('success', 'Enviamos um novo link de verificação para o seu e-mail.');
    }
}
