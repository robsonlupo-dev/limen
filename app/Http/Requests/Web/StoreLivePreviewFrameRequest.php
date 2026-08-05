<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use App\Services\LivePreviewService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Frame de preview da live enviado pelo <LiveRoom> da performer a cada ~10s
 * (Sprint 15, PR #143). Rota WEB consumida por fetch → FailsValidationAsJson.
 *
 * `frame` é o data URL `data:image/jpeg;base64,...` do canvas. O `max` do STRING
 * barra o payload gigante ANTES de decodificar (defesa de memória); o teto de
 * BYTES decodificados (50KB) e o sniff JPEG ficam no controller/serviço, sobre os
 * bytes reais. Base64 infla ~1,37×; 50KB → ~70KB de string + prefixo, então
 * 80_000 chars dá folga sem deixar passar um upload fora de escala.
 */
class StoreLivePreviewFrameRequest extends FormRequest
{
    use FailsValidationAsJson;

    /** Prefixo obrigatório do data URL (só JPEG). */
    public const DATA_URL_PREFIX = 'data:image/jpeg;base64,';

    public function authorize(): bool
    {
        return true; // role:performer + feature:live + performer-active são gate de rota.
    }

    public function rules(): array
    {
        return [
            'frame' => ['required', 'string', 'max:80000', 'starts_with:'.self::DATA_URL_PREFIX],
        ];
    }

    /**
     * Bytes JPEG decodificados, ou null se o base64 for inválido. O teto de bytes
     * (LivePreviewService::MAX_BYTES) e o sniff são checados no controller.
     */
    public function decodedFrame(): ?string
    {
        $base64 = substr($this->input('frame'), strlen(self::DATA_URL_PREFIX));
        $bytes = base64_decode($base64, strict: true);

        return $bytes === false ? null : $bytes;
    }
}
