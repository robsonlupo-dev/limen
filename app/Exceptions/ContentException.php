<?php

namespace App\Exceptions;

use DomainException;

/**
 * Recusa de domínio no fluxo de conteúdo permanente (M.4). `reason` estável para
 * o front; o controller traduz para HTTP. Como o Vue fala com rotas WEB, quem
 * responde precisa de `response()->json()` explícito (convenção das duas portas).
 *
 * Status por motivo (o controller mapeia):
 *  - OFFLINE     → 404 (peça removida / performer fora do ar; não confirma estado dela)
 *  - FORBIDDEN   → 403 (tier insuficiente — o upsell do Modelo C, M.13.13)
 *  - ALREADY     → 422 (já desbloqueado, ou já grátis para assinante — no-op)
 *  - SELF        → 422 (a própria performer não desbloqueia a própria peça)
 *  - UNDER_REVIEW→ 409 (remoção congelada por denúncia em aberto)
 *  - INVALID_PRICE → 422 (preço fora do piso/passo, no publish)
 */
class ContentException extends DomainException
{
    public const OFFLINE = 'offline';

    public const FORBIDDEN = 'forbidden';

    public const ALREADY = 'already';

    public const SELF = 'self';

    public const UNDER_REVIEW = 'under_review';

    public const INVALID_PRICE = 'invalid_price';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function offline(): self
    {
        return new self(self::OFFLINE, 'Conteúdo indisponível.');
    }

    public static function forbidden(): self
    {
        return new self(self::FORBIDDEN, 'Seu círculo não alcança este conteúdo.');
    }

    public static function already(): self
    {
        return new self(self::ALREADY, 'Você já tem acesso a este conteúdo.');
    }

    public static function self(): self
    {
        return new self(self::SELF, 'Você não pode desbloquear o próprio conteúdo.');
    }

    public static function underReview(): self
    {
        return new self(self::UNDER_REVIEW, 'Conteúdo sob análise não pode ser removido.');
    }

    public static function invalidPrice(): self
    {
        return new self(self::INVALID_PRICE, 'Preço inválido: mínimo 5 tokens, em múltiplos de 5.');
    }
}
