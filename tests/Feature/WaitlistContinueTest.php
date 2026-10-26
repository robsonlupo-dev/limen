<?php

use App\Models\WaitlistEntry;
use App\Services\Waitlist\WaitlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Tela de continuação da lista de espera (feat/waitlist-continue-screen):
 * confirme o e-mail + opt-in de WhatsApp + convide. Chatwoot desligado nos testes.
 */
beforeEach(function () {
    config(['chatwoot.enabled' => false]);
    $this->svc = app(WaitlistService::class);
});

function wlEntry(string $role = 'member', string $email = 'pessoa@gmail.com'): WaitlistEntry
{
    $data = ['name' => 'Fulana de Tal', 'email' => $email, 'role' => $role, 'age_confirmed' => true];
    if ($role === 'performer') {
        $data['world'] = 'mulheres';
    }

    return test()->svc->join($data, null, '127.0.0.1')['entry'];
}

it('o cadastro na lista redireciona para a tela de continuação e guarda a sessão', function () {
    \Illuminate\Support\Facades\Mail::fake();
    $this->post(route('waitlist.store'), [
        'name' => 'Maria Teste',
        'email' => 'maria.lista@gmail.com',
        'role' => 'member',
        'age_confirmed' => true,
    ])->assertRedirect(route('waitlist.continue'));

    $entry = WaitlistEntry::where('email', 'maria.lista@gmail.com')->firstOrFail();
    expect(session('waitlist_entry_id'))->toBe($entry->id);
});

it('a tela de continuação entrega papel, e-mail mascarado e link de convite', function () {
    $entry = wlEntry('performer', 'criadora.lista@gmail.com');

    $this->withSession(['waitlist_entry_id' => $entry->id])
        ->get(route('waitlist.continue'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Waitlist/Continue')
            ->where('waitlist.role', 'performer')
            ->where('waitlist.email_masked', 'cr•••@gmail.com')
            ->whereNot('waitlist.invite_link', null)
        );
});

it('sem sessão, a continuação volta para a home', function () {
    $this->get(route('waitlist.continue'))->assertRedirect(route('landing'));
});

it('grava o WhatsApp (E.164 + consentimento) no registro da lista', function () {
    $entry = wlEntry();

    $this->withSession(['waitlist_entry_id' => $entry->id])
        ->postJson(route('waitlist.whatsapp.store'), ['whatsapp' => '(11) 91234-5678', 'consent' => true])
        ->assertOk()
        ->assertJsonPath('ok', true);

    $entry->refresh();
    expect($entry->whatsapp)->toBe('+5511912345678')
        ->and($entry->whatsapp_consent_at)->not->toBeNull();
});

it('recusa o opt-in sem consentimento', function () {
    $entry = wlEntry();

    $this->withSession(['waitlist_entry_id' => $entry->id])
        ->postJson(route('waitlist.whatsapp.store'), ['whatsapp' => '(11) 91234-5678', 'consent' => false])
        ->assertStatus(422)
        ->assertJsonValidationErrors('consent');

    expect($entry->fresh()->whatsapp)->toBeNull();
});

it('recusa número de celular inválido', function () {
    $entry = wlEntry();

    $this->withSession(['waitlist_entry_id' => $entry->id])
        ->postJson(route('waitlist.whatsapp.store'), ['whatsapp' => '123', 'consent' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors('whatsapp');
});

it('sem sessão, o opt-in de WhatsApp dá 419', function () {
    $this->postJson(route('waitlist.whatsapp.store'), ['whatsapp' => '(11) 91234-5678', 'consent' => true])
        ->assertStatus(419);
});

it('revoga o opt-in no destroy', function () {
    $entry = wlEntry();
    $entry->forceFill(['whatsapp' => '+5511912345678', 'whatsapp_consent_at' => now()])->save();

    $this->withSession(['waitlist_entry_id' => $entry->id])
        ->deleteJson(route('waitlist.whatsapp.destroy'))
        ->assertOk();

    $entry->refresh();
    expect($entry->whatsapp)->toBeNull()
        ->and($entry->whatsapp_consent_at)->toBeNull();
});

it('reenvia o e-mail de confirmação para o registro da sessão', function () {
    \Illuminate\Support\Facades\Mail::fake();
    $entry = wlEntry();

    $this->withSession(['waitlist_entry_id' => $entry->id])
        ->postJson(route('waitlist.continue.resend'), [])
        ->assertOk()
        ->assertJsonPath('ok', true);

    // A mailable é ShouldQueue — o Mail::fake registra como ENFILEIRADA, não enviada.
    \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\WaitlistConfirmationMail::class);
});
