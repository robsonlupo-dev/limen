<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use App\Rules\SafeProfileText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Enquete que a performer prende a um story (roadmap social, Onda 1b).
 *
 * Pergunta e cada opção são texto DA PERFORMER, público para quem alcança o story
 * — passam pelo MESMO filtro anti-contato da bio/destaque (SafeProfileText): uma
 * opção não pode virar "meu zap é...". Conveniência de UI; o
 * `StoryInteractionService` reconfere (ownership, não-exclusivo, nº de opções) — a
 * 2ª porta não passa por aqui. Rota web → JSON via trait.
 */
class AttachPollRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $min = (int) config('stories.polls.min_options', 2);
        $max = (int) config('stories.polls.max_options', 4);

        return [
            'prompt' => ['required', 'string', 'max:'.(int) config('stories.polls.prompt_max_length', 80), new SafeProfileText],
            'options' => ['required', 'array', 'min:'.$min, 'max:'.$max],
            'options.*' => ['required', 'string', 'max:'.(int) config('stories.polls.option_max_length', 30), new SafeProfileText],
        ];
    }

    public function messages(): array
    {
        return [
            'prompt.required' => 'Escreva a pergunta da enquete.',
            'options.required' => 'A enquete precisa de opções.',
            'options.min' => 'A enquete precisa de pelo menos :min opções.',
            'options.max' => 'A enquete aceita no máximo :max opções.',
            'options.*.required' => 'Nenhuma opção pode ficar vazia.',
        ];
    }
}
