<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Compartilhamento de uma foto efêmera com UMA performer.
 *
 * O `exists` confirma só que o perfil existe. Quem decide se ESTE membro pode
 * mandar o rosto para ELA é `MemberPhotoService::shareWith()`, que exige chat
 * ativo — regra de produto, e portanto do Service, não do Form Request (item 9
 * do CLAUDE.md: a segunda porta de entrada nasceria sem o gate).
 */
class ShareMemberPhotoRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'performer_profile_id' => [
                'required',
                'integer',
                // `whereNull('deleted_at')` porque a regra `exists` consulta a
                // TABELA e não o model: sem isso ela aceita perfil encerrado, e
                // o corte acabaria dependendo por acidente do global scope de
                // quem for buscar o perfil depois.
                Rule::exists('performer_profiles', 'id')->whereNull('deleted_at'),
            ],
        ];
    }
}
