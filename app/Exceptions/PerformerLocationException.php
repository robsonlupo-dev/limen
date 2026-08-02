<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Invariante de localização violada no PerformerLocationService (Sprint 13).
 *
 * O Form Request (StoreLocationsRequest) é a primeira barreira e devolve 422 com
 * erros de campo. Estas exceções são a defesa DENTRO do serviço, sob lock — o
 * mesmo padrão do BoostService/SavedSearchService: a regra vive no dono, não só
 * no request, para a próxima porta que chamar o serviço não nascer sem ela.
 */
class PerformerLocationException extends RuntimeException
{
    public const LIMIT = 'limit';

    public const PRIMARY = 'primary';

    public const DUPLICATE = 'duplicate';

    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }

    public static function limit(): self
    {
        return new self(self::LIMIT, 'No máximo '.\App\Models\PerformerProfile::MAX_LOCATIONS.' localizações.');
    }

    public static function primary(): self
    {
        return new self(self::PRIMARY, 'Exatamente uma localização deve ser a principal.');
    }

    public static function duplicate(): self
    {
        return new self(self::DUPLICATE, 'Localização repetida (mesmo estado e cidade).');
    }
}
