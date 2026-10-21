<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use App\Rules\SafeProfileText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Título de uma coleção de destaque (criar/renomear). Texto PÚBLICO — passa pelo
 * mesmo filtro anti-contato da bio (SafeProfileText). Autorização na rota.
 *
 * FailsValidationAsJson (achado do UAT): sem o trait, a validação numa rota web
 * vira redirect-com-sessão e o fetch do front recebia HTML — a tela mostrava o
 * genérico "Não foi possível confirmar a ação" em vez do motivo real ("@" barrado,
 * título acima de 30). Com o trait, o motivo chega como JSON e o errorMessage exibe.
 */
class HighlightTitleRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.(int) config('stories.highlights.title_max_length', 30), new SafeProfileText],
        ];
    }
}
