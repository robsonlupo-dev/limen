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

it('nenhum dos dois e-mails de entrada carrega imagem REMOTA (pixel de leitura)', function () {
    $entry = app(WaitlistService::class)->join([
        'name' => 'Teste Pixel', 'email' => 'pixel@gmail.com', 'role' => 'member', 'age_confirmed' => true,
    ], null, '127.0.0.1')['entry'];

    // A foto da porta é EMBUTIDA: ao enviar de verdade (render do Mailable), o
    // <img> aparece com src="cid:...", servido de dentro do e-mail. O que não
    // pode existir é src remoto (http/https/protocol-relative) — esse sim seria
    // pixel de leitura, denunciando quando/onde o e-mail foi aberto.
    $confirmation = (new WaitlistConfirmationMail($entry))->render();
    expect($confirmation)
        ->not->toContain('src="http')
        ->not->toContain("src='http")
        ->not->toContain('src="//');

    // O verify renderizado SEM $message (preview) cai no arco CSS — nem <img> tem.
    expect(verifyEmailHtml())->not->toContain('src="http');
});

it('a foto da porta é EMBUTIDA (CID) — nunca <img> remoto nos templates de e-mail', function () {
    // A arte viaja dentro do e-mail ($message->embed); src="http..." seria
    // pixel de leitura (PIXEL_AUDIT). O render sem $message cai no arco CSS —
    // é por isso que os testes acima seguem sem ver <img>.
    foreach ([
        'resources/views/emails/verify-email.blade.php',
        'resources/views/emails/waitlist/confirmation.blade.php',
    ] as $tpl) {
        $src = file_get_contents(base_path($tpl));
        expect($src)->toContain('$message->embed(public_path(\'email/hero-porta.jpg\'))')
            ->not->toContain('src="http');
    }
    expect(file_exists(public_path('email/hero-porta.jpg')))->toBeTrue();
});
