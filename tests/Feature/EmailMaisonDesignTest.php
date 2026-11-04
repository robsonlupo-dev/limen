<?php

use App\Mail\WaitlistConfirmationMail;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\Waitlist\WaitlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── E-mails no vestido maison (feat/growth-entries, PO 04/11) ───────────────
//
// O verify-email saiu do tema markdown padrão do Laravel para o HTML escuro da
// casa; os dois e-mails de entrada (verify + waitlist confirmation) ganharam a
// moldura dupla. A disciplina do PIXEL_AUDIT continua: NENHUM <img> — imagem
// remota em e-mail é pixel de leitura, e o Limen não rastreia abertura.

function verifyEmailHtml(string $role = 'consumer'): string
{
    config(['referral.enabled' => true]);
    $user = User::factory()->create(['role' => $role]);
    $mm = (new VerifyEmailNotification)->toMail($user);

    return view($mm->view, $mm->viewData)->render();
}

it('verify-email do membro sai no vestido escuro com o CTA e os bônus', function () {
    $html = verifyEmailHtml('consumer');

    expect($html)
        ->toContain('A porta está entreaberta.')
        ->toContain('Confirmar meu e-mail')
        ->toContain('Depois de confirmar, você libera')
        ->toContain('Seu link de convite')
        ->toContain('#0a0a0a') // fundo maison, não o tema claro do markdown
        ->not->toContain('<img');
});

it('verify-email da criadora usa o palco e o bônus de 40', function () {
    $html = verifyEmailHtml('performer');

    expect($html)
        ->toContain('Seu palco está quase pronto.')
        ->toContain('+40 tokens por criadora indicada')
        ->toContain('Você fica com 80%')
        ->not->toContain('<img');
});

it('nenhum dos dois e-mails de entrada carrega imagem remota (pixel de leitura)', function () {
    $entry = app(WaitlistService::class)->join([
        'name' => 'Teste Pixel', 'email' => 'pixel@gmail.com', 'role' => 'member', 'age_confirmed' => true,
    ], null, '127.0.0.1')['entry'];

    expect((new WaitlistConfirmationMail($entry))->render())->not->toContain('<img');
    expect(verifyEmailHtml())->not->toContain('<img');
});
