<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use App\Models\PerformerStory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Publicação de um Story (docs/SECURITY_ISSUES.md § 2.3 e § 2.5).
 *
 * ── v1 é só imagem, e a validação é por CONTEÚDO ────────────────────────────
 * Decisão nº 2 do PO: `mimes:jpeg,png`, o mesmo conjunto do `SubmitKycRequest`.
 * `mimes` e não `mimetypes` porque o Laravel resolve o tipo pelo conteúdo via
 * `guessExtension()`, não pelo header `Content-Type` do upload — que é escolhido
 * pelo cliente. E mesmo isso é só a primeira porta: quem decide de verdade é o
 * `ImageProcessingService`, que lê o header com `getimagesize()` (antes de
 * qualquer decodificação) e re-encoda a partir do bitmap.
 *
 * Vídeo entra no Sprint 10, e não é só somar um mime: depende da estratégia de
 * serving sem cifra em memória (§ 2.5) — o bloqueio que as FC Sessions já
 * encontraram. Não relaxe esta lista antes disso.
 *
 * O `max:5120` é o mesmo teto da foto efêmera. Aqui não há o overhead de 1.78x
 * do `Crypt` (o Story não é cifrado), então 5 MB são 5 MB em disco — o teto está
 * amarrado ao pico de MEMÓRIA do re-encode, que é o do `config/image.php`, e não
 * ao volume.
 *
 * `visibility_level` é validado aqui E no Service: este Form Request é a
 * conveniência de UI, e `PerformerStoryService::publish()` é o guard — a segunda
 * porta de entrada que aparecer não passa por aqui.
 */
class StoreStoryRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'imagem' => ['required', 'file', 'mimes:jpeg,png', 'max:5120'],
            'visibility_level' => ['required', 'string', Rule::in(PerformerStory::VISIBILITY_LEVELS)],
        ];
    }

    public function messages(): array
    {
        return [
            'imagem.required' => 'Escolha uma imagem para publicar.',
            'imagem.mimes' => 'O story precisa ser JPEG ou PNG. Vídeo ainda não é suportado.',
            'imagem.max' => 'A imagem precisa ter no máximo 5 MB.',
            'visibility_level.required' => 'Escolha quem pode ver este story.',
            'visibility_level.in' => 'Nível de visibilidade inválido.',
        ];
    }
}
