<?php

namespace App\Http\Requests\Web;

use App\Rules\SafeProfileText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Define a meta de gorjeta da performer (roadmap social, Onda 4 — §4.2). `title` é
 * texto PÚBLICO — passa pelo mesmo filtro anti-contato da bio (SafeProfileText). O
 * alvo é em tokens, com piso/teto de config. A autorização (é performer ativa, dona do
 * próprio perfil) fica na rota/controller.
 */
class SetTipGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.(int) config('monetization.tip_goal.title_max_length', 80), new SafeProfileText],
            'target' => [
                'required',
                'integer',
                'min:'.(int) config('monetization.tip_goal.min_target', 10),
                'max:'.(int) config('monetization.tip_goal.max_target', 1000000),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Dê um título para a meta.',
            'target.required' => 'Defina um alvo em tokens.',
            'target.min' => 'O alvo mínimo é :min tokens.',
        ];
    }
}
