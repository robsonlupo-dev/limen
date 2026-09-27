<?php

namespace App\Http\Requests\Web;

use App\Rules\SafeProfileText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Título de uma coleção de destaque (criar/renomear). Texto PÚBLICO — passa pelo
 * mesmo filtro anti-contato da bio (SafeProfileText). Autorização na rota.
 */
class HighlightTitleRequest extends FormRequest
{
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
