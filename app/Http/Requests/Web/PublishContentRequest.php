<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use App\Models\PerformerContent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Publicação de conteúdo permanente (M.4). `mimes` valida pelo CONTEÚDO
 * (guessExtension), não pelo header — mas quem enforça de verdade é o
 * ImageProcessingService (re-encode a partir do bitmap). O piso/passo do preço é
 * revalidado no PerformerContentService (segunda porta que aparecer não passa por
 * aqui) — aqui é conveniência de UI.
 */
class PublishContentRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'arquivo' => ['required', 'file', 'mimes:jpeg,png', 'max:10240'],
            'nivel' => ['required', 'string', Rule::in(PerformerContent::LEVELS)],
            // min 5 + múltiplo de 5 (M.4); a dona da regra é TokenCreditPolicy.
            'preco' => ['required', 'integer', 'min:5', 'max:100000'],
        ];
    }

    public function messages(): array
    {
        return [
            'arquivo.required' => 'Escolha uma imagem para publicar.',
            'arquivo.mimes' => 'A imagem precisa ser JPEG ou PNG.',
            'arquivo.max' => 'A imagem precisa ter no máximo 10 MB.',
            'nivel.in' => 'Nível de acesso inválido.',
            'preco.min' => 'O preço mínimo é 5 tokens.',
        ];
    }
}
