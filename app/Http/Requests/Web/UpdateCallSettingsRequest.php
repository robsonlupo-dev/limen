<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Configuração de chamada privada da performer (Sprint 15). Rota WEB consumida por
 * fetch → FailsValidationAsJson (422 JSON, não redirect de sessão).
 *
 * `price_per_minute` nullable = a performer deixa de aceitar chamadas. Quando
 * presente: piso `call_min_price_per_minute` (5) e múltiplo de `call_price_step`
 * (5) — M.13.7. `max_duration_minutes` nullable = sem limite. Os NÚMEROS vêm da
 * config (fonte canônica); o CallService revalida como 2ª linha.
 */
class UpdateCallSettingsRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true; // role:performer + performer-active + documents.accepted são gate de rota.
    }

    public function rules(): array
    {
        $floor = (int) config('monetization.call_min_price_per_minute');
        $step = (int) config('monetization.call_price_step');

        return [
            'price_per_minute' => [
                'present', 'nullable', 'integer',
                'min:'.$floor,
                'max:100000',
                Rule::when($step > 0, ['multiple_of:'.$step]),
            ],
            'max_duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'],
            // Duração-padrão do slot de agendamento (feat/scheduled-call-v1). NULL =
            // usa o default de config. Faixa 5–240 min (bloco de atendimento).
            'slot_minutes' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:240'],
        ];
    }

    public function pricePerMinute(): ?int
    {
        $value = $this->input('price_per_minute');

        return $value === null ? null : (int) $value;
    }

    public function maxDurationMinutes(): ?int
    {
        $value = $this->input('max_duration_minutes');

        return $value === null ? null : (int) $value;
    }

    public function slotMinutes(): ?int
    {
        $value = $this->input('slot_minutes');

        return $value === null ? null : (int) $value;
    }
}
