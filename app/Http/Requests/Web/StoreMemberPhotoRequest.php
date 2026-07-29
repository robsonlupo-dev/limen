<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use App\Models\MemberPhoto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Upload de foto efêmera do membro (docs/SECURITY_ISSUES.md § 1.6).
 *
 * O `max:5120` é o número do qual sai o dimensionamento de disco do § 1.6: com
 * o overhead medido de 1.78x do `Crypt::encryptString`, 5 MB viram ~9 MB em
 * disco, e o cap de 5 ativas fecha o teto por membro em ~45 MB.
 *
 * `mimes` (e não `mimetypes`) porque o Laravel resolve o tipo pelo CONTEÚDO via
 * `guessExtension()`, não pelo header `Content-Type` do upload — que é escolhido
 * pelo cliente. Mesmo assim isto é só a primeira porta: quem decide de verdade é
 * o `ImageProcessingService`, que lê o header com `getimagesize()` e re-encoda a
 * partir do bitmap.
 */
class StoreMemberPhotoRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'foto' => ['required', 'file', 'mimes:jpeg,png', 'max:5120'],
            // O menu de três, do § 1.2. A validação aqui é conveniência de UI —
            // quem enforça é MemberPhotoService::create(), porque a segunda
            // porta de entrada que aparecer não passa por este Form Request.
            'ttl_horas' => ['required', 'integer', Rule::in(MemberPhoto::TTL_HOURS)],
        ];
    }

    public function messages(): array
    {
        return [
            'foto.required' => 'Escolha uma foto para enviar.',
            'foto.mimes' => 'A foto precisa ser JPEG ou PNG.',
            'foto.max' => 'A foto precisa ter no máximo 5 MB.',
            'ttl_horas.in' => 'Escolha um prazo válido: 24 horas, 72 horas ou 7 dias.',
        ];
    }
}
