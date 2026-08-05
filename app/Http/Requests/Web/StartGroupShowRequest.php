<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A performer inicia um group show (Sprint 15). Rota WEB consumida por fetch →
 * FailsValidationAsJson (422 JSON). `price_per_minute` piso 5, múltiplo de 5
 * (M.13.7). `max_participants` de MEMBROS, 2..livekit.max_participants_group (a
 * sala nasce com esse +1 para a performer). Os NÚMEROS vêm da config; o
 * GroupShowService revalida como 2ª linha.
 */
class StartGroupShowRequest extends FormRequest
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
        $cap = (int) config('livekit.max_participants_group');

        return [
            'price_per_minute' => [
                'required', 'integer',
                'min:'.$floor,
                'max:100000',
                Rule::when($step > 0, ['multiple_of:'.$step]),
            ],
            'max_participants' => ['required', 'integer', 'min:2', 'max:'.$cap],
        ];
    }

    public function pricePerMinute(): int
    {
        return (int) $this->input('price_per_minute');
    }

    public function maxParticipants(): int
    {
        return (int) $this->input('max_participants');
    }
}
