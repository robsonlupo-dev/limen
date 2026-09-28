<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use App\Services\StoryReactionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reação rápida a um story (roadmap social, Onda 1b).
 *
 * `reaction` validado contra o conjunto ÚNICO do config (via
 * `StoryReactionService::allowedReactions`), aqui como a conveniência de UI e no
 * service como o guard — a 2ª porta de entrada que aparecer não passa por aqui.
 * Rota web: a exceção não vira JSON sozinha fora de `api/*`, daí o trait.
 */
class ReactToStoryRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reaction' => ['required', 'string', Rule::in(StoryReactionService::allowedReactions())],
        ];
    }

    public function messages(): array
    {
        return [
            'reaction.required' => 'Escolha uma reação.',
            'reaction.in' => 'Reação inválida.',
        ];
    }
}
