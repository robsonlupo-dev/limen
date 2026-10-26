<?php

use App\Models\Referral;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\AuthService;
use App\Services\ReferralService;
use App\Services\Waitlist\WaitlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * Ponte pré-lançamento → go-live (feat/referral-waitlist-bridge): a indicação feita
 * na LISTA DE ESPERA é herdada quando a pessoa cria a conta real com o mesmo e-mail.
 */
beforeEach(function () {
    config()->set('referral.enabled', true);
    Mail::fake();
    Notification::fake();
    $this->wl = app(WaitlistService::class);
    $this->auth = app(AuthService::class);
    $this->ref = app(ReferralService::class);
});

/** Monta o grafo da lista de espera: referrerEntry indica referredEntry. */
function wlGraph(WaitlistService $wl, string $referrerEmail, string $referredEmail): array
{
    $referrerEntry = $wl->join(
        ['name' => 'Indicador', 'email' => $referrerEmail, 'role' => 'member', 'age_confirmed' => true],
        null, '10.0.0.1',
    )['entry'];

    $referredEntry = $wl->join(
        ['name' => 'Indicado', 'email' => $referredEmail, 'role' => 'member', 'age_confirmed' => true],
        $referrerEntry, '10.0.0.2',
    )['entry'];

    return [$referrerEntry, $referredEntry];
}

/** Cadastro de conta real "de go-live" (consumer), com CPF próprio. */
function goLiveConsumer(AuthService $auth, string $email, string $cpf = '529.982.247-25'): User
{
    return $auth->registerConsumer([
        'name' => 'Conta Real',
        'email' => $email,
        'password' => 'Senha123',
        'birthdate' => '1990-06-15',
        'cpf' => $cpf,
        'terms_version' => '1.0',
    ]);
}

it('sanity: a lista de espera registra quem indicou quem', function () {
    [$referrerEntry, $referredEntry] = wlGraph($this->wl, 'ind@gmail.com', 'novo@gmail.com');

    expect($referredEntry->fresh()->referred_by)->toBe($referrerEntry->id);
});

it('herda a indicação da lista de espera quando o indicador já tem conta real', function () {
    [$referrerEntry] = wlGraph($this->wl, 'ind@gmail.com', 'novo@gmail.com');

    // Indicador cria a conta real ANTES do indicado.
    $referrer = goLiveConsumer($this->auth, 'ind@gmail.com', '111.444.777-35');

    // Indicado cria a conta real (sem ?ref=) → deve herdar da lista de espera.
    $referred = goLiveConsumer($this->auth, 'novo@gmail.com');

    expect($referred->fresh()->referred_by_user_id)->toBe($referrer->id)
        ->and($referred->fresh()->referred_via)->toBe('waitlist');

    $referral = Referral::where('referred_user_id', $referred->id)->first();
    expect($referral)->not->toBeNull()
        ->and($referral->referrer_user_id)->toBe($referrer->id)
        ->and($referral->status)->toBe('pending');
});

it('reconcilia quando o indicador cria a conta DEPOIS do indicado', function () {
    wlGraph($this->wl, 'ind@gmail.com', 'novo@gmail.com');

    // Indicado cria a conta primeiro — indicador ainda não tem conta → sem vínculo.
    $referred = goLiveConsumer($this->auth, 'novo@gmail.com');
    expect($referred->fresh()->referred_by_user_id)->toBeNull();

    // Indicador cria a conta depois.
    $referrer = goLiveConsumer($this->auth, 'ind@gmail.com', '111.444.777-35');

    // O comando de reconciliação fecha o par.
    $linked = $this->ref->reconcileWaitlistReferrals();

    expect($linked)->toBe(1)
        ->and($referred->fresh()->referred_by_user_id)->toBe($referrer->id);
});

it('não inventa vínculo quando não houve indicação na lista de espera', function () {
    // Entra na lista SEM indicador.
    $this->wl->join(['name' => 'Solo', 'email' => 'solo@gmail.com', 'role' => 'member', 'age_confirmed' => true], null, '10.0.0.9');

    $user = goLiveConsumer($this->auth, 'solo@gmail.com');

    expect($user->fresh()->referred_by_user_id)->toBeNull();
    expect(Referral::where('referred_user_id', $user->id)->exists())->toBeFalse();
});
