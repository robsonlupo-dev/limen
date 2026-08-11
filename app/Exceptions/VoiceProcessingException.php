<?php

namespace App\Exceptions;

use DomainException;

/**
 * Falha ao sanitizar uma intro de voz enviada (App\Services\VoiceProcessingService).
 *
 * Exceção de DOMÍNIO: a entrada é hostil por definição. `reason` estável para o
 * front (padrão do VideoProcessingException). O upload traduz `too_long`/
 * `unreadable`/`ffmpeg_missing` para 422 no ato; falhas de encode acontecem no
 * job assíncrono e viram `status=failed` + mensagem para a performer.
 */
class VoiceProcessingException extends DomainException
{
    public const FFMPEG_MISSING = 'ffmpeg_missing';

    public const TOO_LONG = 'too_long';

    public const UNREADABLE = 'unreadable';

    public const ENCODE_FAILED = 'encode_failed';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /** ffmpeg/ffprobe não encontrado no servidor — falha de infra, não do usuário. */
    public static function ffmpegMissing(): self
    {
        return new self(self::FFMPEG_MISSING, 'O processamento de áudio está indisponível no momento.');
    }

    /** Duração acima do teto (config voice.max_duration_seconds). */
    public static function tooLong(int $maxSeconds): self
    {
        return new self(self::TOO_LONG, "A apresentação de voz excede a duração máxima de {$maxSeconds} segundos.");
    }

    /** ffprobe não reconheceu o arquivo como áudio (renomeado / corrompido). */
    public static function unreadable(): self
    {
        return new self(self::UNREADABLE, 'O arquivo enviado não é um áudio válido.');
    }

    /** ffmpeg falhou no re-encode — a performer tenta de novo. */
    public static function encodeFailed(): self
    {
        return new self(self::ENCODE_FAILED, 'Não foi possível processar o áudio. Tente enviar novamente.');
    }
}
