<?php

namespace App\Exceptions;

use App\Models\MemberPhoto;
use DomainException;

/**
 * Recusa de domínio no fluxo de foto efêmera (App\Services\MemberPhotoService).
 *
 * Mesmo padrão do ImageProcessingException e do ChatException: `reason` estável
 * para o front, e o chamador traduz para HTTP. Como o Vue fala com rotas WEB, a
 * exceção não vira JSON sozinha — quem responde precisa de `response()->json()`
 * explícito (convenção das duas portas de auth, CLAUDE.md).
 */
class MemberPhotoException extends DomainException
{
    public const ACTIVE_LIMIT_REACHED = 'active_limit_reached';

    public const EXPIRED = 'expired';

    public const INVALID_TTL = 'invalid_ttl';

    public const FORBIDDEN = 'forbidden';

    public const NO_ACTIVE_CHAT = 'no_active_chat';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /** Cap de fotos ativas simultâneas (§ 1.6). Verificado no submit, sob lock. */
    public static function activeLimitReached(): self
    {
        return new self(
            self::ACTIVE_LIMIT_REACHED,
            'Você já tem '.MemberPhoto::ACTIVE_LIMIT.' fotos ativas. '
            .'Apague uma ou espere expirar para enviar outra.',
        );
    }

    /**
     * Prazo vencido, conferido na LEITURA (§ 1.3).
     *
     * A mensagem não distingue "a foto expirou" de "seu acesso expirou": a
     * segunda diria à performer que a foto continua viva para outra pessoa, que
     * é informação sobre o membro, não sobre o acesso dela.
     */
    public static function expired(): self
    {
        return new self(
            self::EXPIRED,
            'Esta foto não está mais disponível.',
        );
    }

    /**
     * O ator não é dono da foto (ou não é a performer que recebeu o acesso).
     *
     * A MENSAGEM é deliberadamente idêntica à de expired(): quem tenta ler o
     * acesso de outra pessoa não pode aprender, pela resposta, se aquele id
     * existe e está vivo. O `reason` distingue os dois casos para o log e para
     * o teste — a tela, não.
     */
    public static function forbidden(): self
    {
        return new self(
            self::FORBIDDEN,
            'Esta foto não está mais disponível.',
        );
    }

    /**
     * Compartilhamento sem chat ativo com aquela performer (decisão do PO).
     *
     * O rosto não é conteúdo de catálogo: só vai para quem o membro já escolheu
     * conversar E está pagando a janela. Sem isso, a foto viraria uma superfície
     * membro→performer que contorna o gate do Interesse Controlado, e um membro
     * poderia empurrar o próprio rosto para qualquer perfil da plataforma.
     */
    public static function noActiveChat(): self
    {
        return new self(
            self::NO_ACTIVE_CHAT,
            'Você só pode compartilhar fotos com performers com quem tem um chat ativo.',
        );
    }

    /**
     * TTL fora do menu (§ 1.2 — 24h / 72h / 7 dias).
     *
     * Erro de CHAMADOR, não do usuário: o Form Request do endpoint valida antes.
     * A checagem aqui existe para que um segundo ponto de entrada não nasça
     * aceitando um TTL arbitrário — o mesmo raciocínio do guard no Service em
     * vez de no controller (item 9 do CLAUDE.md).
     */
    public static function invalidTtl(int $hours): self
    {
        return new self(
            self::INVALID_TTL,
            "TTL não permitido para foto efêmera: {$hours}h.",
        );
    }
}
