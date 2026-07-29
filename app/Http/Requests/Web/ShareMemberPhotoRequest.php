<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Compartilhamento de uma foto efêmera com UMA performer.
 *
 * O `exists` confirma só que o id aponta para alguma linha. Quem decide se ESTE
 * membro pode mandar o rosto para ELA é `MemberPhotoService::shareWith()`, que
 * exige chat ativo e performer de pé — regra de produto, e portanto do Service,
 * não do Form Request (item 9 do CLAUDE.md: a segunda porta de entrada nasceria
 * sem o gate).
 *
 * Consequência que a regra de validação NÃO pode reintroduzir: toda recusa
 * relativa à performer tem de sair com o mesmo corpo. Ver o comentário em
 * rules().
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
            // **Sem `whereNull('deleted_at')`, de propósito.** A regra `exists`
            // consulta a TABELA e não o model, então ela aceita perfil
            // encerrado — e é isso que se quer aqui: o corte por encerramento
            // vive no controller, que devolve a MESMA recusa de "sem chat
            // ativo".
            //
            // Cortar na validação parece mais rigoroso e é pior: o corpo do 422
            // da validação (`errors.performer_profile_id`) é distinguível do
            // corpo da recusa do Service (`reason: no_active_chat`), e o membro
            // tem o id da parceira nas props do Inertia. A diferença entre as
            // duas respostas diria "ela encerrou a conta" — perfil só é
            // soft-deletado pelo DeletionService. Estado da conta dela, não
            // dele, e fato sensível sob LGPD.
            'performer_profile_id' => ['required', 'integer', 'exists:performer_profiles,id'],
        ];
    }
}
