<?php

namespace App\Exceptions;

use DomainException;

/**
 * Falha ao processar uma imagem enviada por usuário
 * (App\Services\ImageProcessingService).
 *
 * É exceção de DOMÍNIO, não de programação: a entrada é hostil por definição
 * (arquivo escolhido pelo remetente), então recusar faz parte da operação
 * normal. O chamador traduz para 422 — e como o frontend Vue fala com rotas
 * WEB, onde exceção não vira JSON sozinha, quem responde precisa de um
 * `response()->json()` explícito.
 *
 * Carrega um `reason` estável para o front, no mesmo padrão do ChatException.
 * As mensagens são deliberadamente genéricas quanto ao motivo técnico: dizer
 * "seu PNG declara 40000px de largura" ensina a calibrar o próximo envio.
 */
class ImageProcessingException extends DomainException
{
    public const NOT_AN_IMAGE = 'not_an_image';

    public const UNSUPPORTED_FORMAT = 'unsupported_format';

    public const DIMENSIONS_TOO_LARGE = 'dimensions_too_large';

    public const UNREADABLE = 'unreadable';

    public const DRIVER_UNSUPPORTED = 'driver_unsupported';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /** `getimagesize()` não reconheceu o arquivo — inclui o polyglot mal formado. */
    public static function notAnImage(): self
    {
        return new self(
            self::NOT_AN_IMAGE,
            'O arquivo enviado não é uma imagem válida.',
        );
    }

    /** Imagem legível, mas de um formato que não entregamos ao decodificador. */
    public static function unsupportedFormat(): self
    {
        return new self(
            self::UNSUPPORTED_FORMAT,
            'Formato de imagem não suportado. Envie JPEG, PNG, GIF ou WebP.',
        );
    }

    /** Barrada pelo teto de eixo ou de área, ANTES de qualquer decodificação. */
    public static function dimensionsTooLarge(): self
    {
        return new self(
            self::DIMENSIONS_TOO_LARGE,
            'A imagem é grande demais para ser processada.',
        );
    }

    /** Header passou nos cortes, mas o decodificador falhou (arquivo truncado/corrompido). */
    public static function unreadable(): self
    {
        return new self(
            self::UNREADABLE,
            'Não foi possível processar esta imagem.',
        );
    }

    /**
     * `config('image.driver')` traz um valor que não sabemos instanciar.
     *
     * Erro de configuração, não do usuário — mas lançar aqui é de propósito:
     * o ponto do driver fixo (§ 1.4) é NÃO ter fallback silencioso.
     */
    public static function driverUnsupported(string $driver): self
    {
        return new self(
            self::DRIVER_UNSUPPORTED,
            "Driver de imagem não suportado: {$driver}.",
        );
    }
}
