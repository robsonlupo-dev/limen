<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A concessão da franquia mensal encostou no teto (M.13.8): parte foi creditada e
 * o excedente entrou na fila de pendência. Sinal para o front/e-mail avisarem o
 * membro que há tokens presos até ele gastar — o consumo (UI/notificação) é
 * follow-up; aqui só emitimos o evento.
 *
 * NÃO é ShouldBroadcast (o Reverb não roda em dev/staging) e NÃO carrega PII:
 * só o id do próprio usuário e números do seu wallet. O `tier` é o Círculo do
 * PRÓPRIO membro sobre a PRÓPRIA carteira — não expõe tier de membro a performer
 * (M.13.10), que fala de superfícies da performer.
 */
class SubscriptionGrantPended
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $userId,
        public string $tier,
        public int $pendedTokens,
        public int $cap,
    ) {}
}
