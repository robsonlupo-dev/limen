<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Voto do membro numa enquete de story (roadmap social, Onda 1b).
 *
 * Só valida o TIPO/forma do índice; o intervalo válido (0..N-1) depende das opções
 * daquela enquete e é conferido no `StoryInteractionService::vote`, junto do
 * paywall. Rota web → JSON via trait.
 */
class VotePollRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'option_index' => ['required', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'option_index.required' => 'Escolha uma opção.',
            'option_index.integer' => 'Opção inválida.',
        ];
    }
}
