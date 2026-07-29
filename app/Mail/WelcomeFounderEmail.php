<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Carta pessoal dos fundadores, enviada uma vez, depois do KYC aprovado.
 *
 * **A regra que governa esta classe é a separação envelope × corpo.**
 *
 * O corpo é pessoal e fala do produto: quem lê já entrou, já passou pelo KYC e
 * sabe onde está. O ENVELOPE — remetente, assunto e o preheader que o cliente
 * de e-mail mostra na lista — é lido por qualquer pessoa que olhe a caixa de
 * entrada por cima do ombro, aparece na notificação da tela bloqueada e é o que
 * o e-mail corporativo de alguém indexa. Nada aí pode denunciar que a pessoa se
 * cadastrou numa plataforma adulta.
 *
 * Na prática:
 *  - **From** é o `MAIL_FROM_ADDRESS` de sempre (`noreply@thelimen.com.br`), só
 *    com o nome de exibição trocado para os fundadores. Não inventamos um
 *    domínio novo: um remetente sem histórico de envio cai em spam, e o SPF/
 *    DKIM configurados valem para este.
 *  - **Subject** é "Bem-vindo ao Limen" — a marca e mais nada. Sem "verificação",
 *    sem "perfil aprovado", sem "conteúdo", sem "+18". O que a marca significa
 *    só sabe quem já conhece.
 *  - **Preheader** (no Blade) segue a mesma regra: é texto de envelope, não de
 *    corpo.
 *
 * Travado por teste (WelcomeFounderEmailTest), com lista de termos proibidos.
 */
class WelcomeFounderEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Assunto. Neutro por decisão do PO — ver o cabeçalho da classe.
     *
     * Constante, e não string solta no envelope(), porque o teste de privacidade
     * assere sobre ele: a asserção tem que apontar para a MESMA fonte que o
     * envio usa, senão passa a medir uma cópia.
     */
    public const SUBJECT = 'Bem-vindo ao Limen';

    /** Nome de exibição do remetente. Pessoal, mas não denuncia nada. */
    public const FROM_NAME = 'Robson & Bruno';

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // Endereço do config (SPF/DKIM já cobrem este domínio); só o nome
            // de exibição muda, para a carta chegar assinada por gente.
            from: new Address(
                (string) config('mail.from.address'),
                self::FROM_NAME,
            ),
            subject: self::SUBJECT,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.welcome',
            with: [
                // Primeiro nome só. O nome composto num "Olá, ..." soa a mala
                // direta, que é o oposto do que esta carta tenta ser.
                'firstName' => Str::of($this->user->name)->trim()->explode(' ')->first(),
                // Texto e destino ÚNICOS para membro e performer, por decisão
                // do PO: é uma carta dos fundadores, não um onboarding — a
                // mesma mensagem para as duas pontas é o que a mantém pessoal
                // em vez de segmentada.
                'ctaUrl' => route('catalog'),
            ],
        );
    }
}
