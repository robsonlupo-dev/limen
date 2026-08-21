<?php

namespace App\Http\Requests;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use App\Models\PerformerProfile;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Envio de gorjeta pelo membro (rota WEB consumida por fetch do Vue). Usa o trait
 * FailsValidationAsJson — sem ele, uma falha de validação numa rota web vira
 * REDIRECT (302) em vez de 422 JSON, e o `fetch` segue o redirect até uma página
 * 200: o `postJson` resolve e o front mostra "gorjeta enviada" para uma requisição
 * que na verdade FALHOU (sem debitar). Era o sucesso falso da sala ao vivo
 * (fix/live-room-actions). Espelha o SendGiftRequest, que já tinha o trait.
 */
class SendTipRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'performer_slug' => ['required', 'string'],
            'amount' => ['required', 'integer', 'min:1', 'max:1000'],
            'message' => ['nullable', 'string', 'max:200'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function resolvedPerformer(): PerformerProfile
    {
        return PerformerProfile::with('user')
            ->where('slug', $this->validated('performer_slug'))
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->where('is_verified', true)
            ->firstOrFail();
    }
}
