<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload de uma foto da galeria do perfil (Sprint 10).
 *
 * Mesma validação por CONTEÚDO dos Stories e do KYC: `mimes:jpeg,png` resolve o
 * tipo pelo conteúdo via `guessExtension()`, não pelo header `Content-Type` do
 * upload (que o cliente escolhe). E é só a primeira porta — quem decide de
 * verdade é o `ImageProcessingService`, que lê o header com `getimagesize()`
 * antes de qualquer decodificação e re-encoda a partir do bitmap.
 *
 * `max:5120` (5 MB) é o mesmo teto do story. Sem cifra aqui (§ R1), 5 MB são
 * 5 MB em disco — o teto está amarrado ao pico de memória do re-encode
 * (config/image.php), não ao volume. O cap de 6 fotos é do PerformerPhotoService,
 * conferido sob lock: aqui não dá para saber a contagem com segurança.
 *
 * `FailsValidationAsJson` porque o Vue consome esta resposta com `fetch` — sem o
 * trait, uma rota web devolveria redirect-com-erros-de-sessão (as duas portas de
 * auth, CLAUDE.md).
 */
class StorePerformerPhotoRequest extends FormRequest
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
        ];
    }

    public function messages(): array
    {
        return [
            'foto.required' => 'Escolha uma imagem para adicionar.',
            'foto.mimes' => 'A foto precisa ser JPEG ou PNG.',
            'foto.max' => 'A imagem precisa ter no máximo 5 MB.',
        ];
    }
}
