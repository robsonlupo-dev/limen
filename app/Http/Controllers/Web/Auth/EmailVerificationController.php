<?php

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\SignupWelcomeData;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailVerificationController extends Controller
{
    public function notice(Request $request, SignupWelcomeData $welcome): Response
    {
        // A tela de "pré-cadastro recebido": confirme o e-mail + bônus + indique +
        // opt-in de WhatsApp. É role-aware (membro × criadora) e reaproveita o
        // SignupWelcomeData, a mesma fonte do e-mail de confirmação.
        return Inertia::render('Auth/VerifyEmail', [
            'welcome' => $welcome->for($request->user()),
        ]);
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
