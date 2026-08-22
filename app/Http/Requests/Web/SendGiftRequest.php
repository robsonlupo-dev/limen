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

    /**
     * Só presente ATIVO do catálogo resolve. Devolve NULL (não `firstOrFail`) quando
     * não há: o catálogo de presentes é PÚBLICO (presentes da Limen, sem PII), então
     * o controller converte o não-encontrado num 422 JSON com motivo REAL — em vez do
     * 404 HTML que o `firstOrFail` produzia, que o fetch do front não consegue ler e
     * vira a mensagem genérica "não foi possível enviar o presente" (o bug da sala ao
     * vivo: um gift_slug que não resolve — catálogo defasado — escondia o 404 real).
     */
    public function resolvedGift(): ?Gift
    {
        return Gift::active()
            ->where('slug', $this->validated('gift_slug'))
            ->first();
    }
}
