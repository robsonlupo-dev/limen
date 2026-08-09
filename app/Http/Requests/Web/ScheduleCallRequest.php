<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Agendamento de chamada — o membro escolhe o horário (feat/scheduled-call-v1).
 * Rota WEB consumida por fetch → FailsValidationAsJson (422 JSON, não redirect).
 *
 * `scheduled_at` é interpretado no FUSO DE EXIBIÇÃO (America/Sao_Paulo por config),
 * não em UTC: o picker manda hora local (ex.: "2026-08-20T14:30"); o service valida
 * a antecedência e a colisão, e o cast do model grava em UTC. Se a string já vier
 * com offset, o Carbon o respeita e o fuso do config é ignorado.
 */
class ScheduleCallRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true; // role:consumer + member.verified + feature:call são gate de rota.
    }

    public function rules(): array
    {
        return [
            // Aceita ISO-8601 / datetime-local. A faixa (min/max lead) é validada no
            // service (fonte única, para o par de respostas não divergir da colisão).
            'scheduled_at' => ['required', 'string', 'date'],
        ];
    }

    /** O horário escolhido, no fuso de exibição (ou o offset embutido na string). */
    public function scheduledAt(): Carbon
    {
        return Carbon::parse(
            (string) $this->input('scheduled_at'),
            config('scheduled_call.display_timezone'),
        );
    }
}
