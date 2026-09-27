<?php

namespace App\Http\Requests\Web;

use App\Rules\SafeProfileText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Define o status do dia da performer (roadmap social, Onda 1a). `body` é texto
 * PÚBLICO — passa pelo mesmo filtro anti-contato da bio (SafeProfileText). A
 * autorização (é performer, dona do próprio perfil) fica na rota/controller.
 */
class SetPerformerStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.(int) config('stories.status.max_length', 140), new SafeProfileText],
            // Contagem regressiva opcional — alvo tem que ser no futuro.
            'countdown_at' => ['nullable', 'date', 'after:now'],
            'countdown_label' => ['nullable', 'string', 'max:'.(int) config('stories.status.label_max_length', 40)],
        ];
    }

    public function messages(): array
    {
        return [
            'countdown_at.after' => 'A contagem regressiva precisa apontar para um horário futuro.',
        ];
    }
}
