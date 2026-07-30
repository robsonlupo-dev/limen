<?php

namespace App\Exceptions;

use DomainException;

/**
 * Recusa de domínio no fluxo de Stories (App\Services\PerformerStoryService).
 *
 * Mesmo padrão do MemberPhotoException e do ChatException: `reason` estável para
 * o front, e o chamador traduz para HTTP. Como o Vue fala com rotas WEB, a
 * exceção não vira JSON sozinha — quem responde precisa de `response()->json()`
 * explícito (convenção das duas portas de auth, CLAUDE.md).
 *
 * ── Por que existem DOIS motivos, e por que eles têm status diferentes ───────
 * `expired` sai em 404 e `forbidden` em 403, e a assimetria com a foto efêmera
 * (onde os dois são 404, para não confirmar que o id existe) é decisão de
 * produto: o Modelo C monetiza dizendo ao membro que existe conteúdo que ele
 * ainda não pode ver — é o "cria incentivo para assinar Círculo" do § 2.3. O que
 * o 403 revela é o que a própria tela do catálogo vai anunciar.
 *
 * O que ele NÃO pode revelar é QUEM viu ou QUANTOS: isso segue fechado no
 * `viewCount()` (faixa, e `null` no exclusivo).
 */
class StoryException extends DomainException
{
    public const EXPIRED = 'expired';

    public const FORBIDDEN = 'forbidden';

    /** Congelado por denúncia em aberto (§ 2.4). Só a deleção manual bate aqui. */
    public const UNDER_REVIEW = 'under_review';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /**
     * Prazo vencido (ou story já destruído), conferido na LEITURA (§ 2.8).
     *
     * A mensagem não distingue "expirou" de "não existe": um id que nunca
     * existiu e um que morreu há duas horas devolvem a mesma coisa, senão o par
     * de respostas enumera o que a performer publicou e quando.
     */
    public static function expired(): self
    {
        return new self(
            self::EXPIRED,
            'Este story não está mais disponível.',
        );
    }

    /**
     * O membro não alcança este nível de visibilidade AGORA — follow e tier são
     * resolvidos no request (§ 2.3), nunca herdados de uma URL assinada.
     */
    public static function forbidden(): self
    {
        return new self(
            self::FORBIDDEN,
            'Este story é exclusivo para outro nível de acesso.',
        );
    }

    /**
     * Deleção manual recusada porque há denúncia em aberto (§ 2.4, parte 2).
     *
     * A mensagem DIZ o motivo, e é decisão: aqui não há oráculo a proteger — a
     * performer é a dona do story e já sabe que ele existe. O que ela ganha em
     * saber é a chance de responder à moderação em vez de tentar de novo; o que
     * a plataforma ganha é não parecer bug. Distinguir isto de "não é seu" é
     * seguro pelo mesmo motivo: quem chega aqui já provou a propriedade.
     */
    public static function underReview(): self
    {
        return new self(
            self::UNDER_REVIEW,
            'Este story está sob análise e não pode ser apagado até a revisão terminar.',
        );
    }

    /** Story que não pertence a quem pediu (porta da performer). */
    public static function notOwner(): self
    {
        return new self(
            self::FORBIDDEN,
            'Este story não está mais disponível.',
        );
    }
}
