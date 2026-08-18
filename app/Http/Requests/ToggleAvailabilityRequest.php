<?php

namespace App\Http\Requests;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Toggle de VISIBILIDADE da performer (fix/panel-polish-v1).
 *
 * Substituiu o "Disponível para conversa" manual do Sprint 11: a presença agora
 * deriva da sessão, e este toggle controla só o OPT-OUT `appear_offline`. O
 * campo é `visible` (desejo de aparecer), traduzido para `appear_offline` no
 * controller.
 *
 * Rota WEB consumida pelo `patchJson` do dashboard — usa FailsValidationAsJson
 * para o erro de validação sair 422 JSON, não redirect com erros de sessão
 * (convenção das duas portas de auth, ver o trait).
 */
class ToggleAvailabilityRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true; // role/2fa/documents.accepted são gate de rota; o dono é resolvido no controller.
    }

    public function rules(): array
    {
        return [
            // Opcional: sem ele, inverte o estado atual. Com ele, o cliente diz
            // se quer aparecer (`true`) ou ficar invisível (`false`) — o que
            // torna o duplo clique / retry inofensivo, mesmo padrão do
            // ToggleDiscreteModeRequest.
            'visible' => ['sometimes', 'boolean'],
        ];
    }

    /** O estado de visibilidade desejado, ou null para inverter o atual. */
    public function desiredVisible(): ?bool
    {
        return $this->has('visible') ? $this->boolean('visible') : null;
    }
}
