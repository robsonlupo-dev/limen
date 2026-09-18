<?php

namespace App\Http\Requests\Moderation;

use App\Models\MemberGalleryPhoto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Decisão do moderador sobre uma foto de galeria de membro
 * (feat/member-gallery-and-profile).
 *
 * A autorização de QUEM (moderator/admin) é do middleware `moderator.access` na
 * rota — aqui só valida o payload. Só dois destinos: `approved` / `rejected`.
 * Motivo OBRIGATÓRIO na recusa (o membro precisa saber por quê para reenviar).
 */
class ModerateMemberPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                MemberGalleryPhoto::STATUS_APPROVED,
                MemberGalleryPhoto::STATUS_REJECTED,
            ])],
            'reject_reason' => ['nullable', 'required_if:status,'.MemberGalleryPhoto::STATUS_REJECTED, 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reject_reason.required_if' => 'Informe o motivo da recusa.',
        ];
    }
}
