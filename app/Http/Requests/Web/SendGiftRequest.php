<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use App\Models\Gift;
use App\Models\PerformerProfile;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Envio de presente pelo membro (rota WEB consumida por fetch do Vue) — por isso
 * o trait FailsValidationAsJson (validação falha em 422 JSON, não redirect).
 */
class SendGiftRequest extends FormRequest
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
            'gift_slug' => ['required', 'string'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /**
     * 404 UNIFORME para slug inexistente, performer não-verificada OU conta
     * inativa (firstOrFail): o par POST não vira oráculo do estado da conta da
     * performer — mesma disciplina de SendTipRequest, do Favorito e do Story.
     */
    public function resolvedPerformer(): PerformerProfile
    {
        return PerformerProfile::with('user')
            ->where('slug', $this->validated('performer_slug'))
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->where('is_verified', true)
            ->firstOrFail();
    }

    /** Só presente ATIVO do catálogo resolve; inexistente/inativo → 404 uniforme. */
    public function resolvedGift(): Gift
    {
        return Gift::active()
            ->where('slug', $this->validated('gift_slug'))
            ->firstOrFail();
    }
}
