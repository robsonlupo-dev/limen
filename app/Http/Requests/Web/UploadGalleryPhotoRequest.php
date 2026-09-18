<?php

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload de foto da galeria do membro (feat/member-profile-v2). Dois arquivos:
 *  - `file`     — o ORIGINAL escolhido pelo membro; vira a variante COMPLETA
 *                 (lightbox). Obrigatório.
 *  - `cropped`  — o recorte 3:4 que o ImageCropper gerou no cliente; vira a
 *                 variante ENQUADRADA (card/miniatura). OPCIONAL — sem ele (JS
 *                 desligado, API futura) o servidor recorta o original no centro.
 *
 * Ambos passam pelo MESMO pipeline endurecido no service (dimensões antes de
 * decodificar, strip EXIF/GPS, CsamScan). A validação aqui é só forma/tamanho —
 * não substitui a sanitização do ImageProcessingService (não confiar no cliente,
 * inclusive no recorte que ele mandou).
 *
 * Request PRÓPRIO em vez de estender o UploadMediaRequest compartilhado: `cropped`
 * é específico deste caminho, e mexer no request comum afetaria os 10 outros
 * uploads que só têm `file`.
 */
class UploadGalleryPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
            // O recorte sai do cropper como JPEG; aceitamos os mesmos formatos de
            // imagem por robustez. Nunca confiado como sanitizado — só como forma.
            'cropped' => ['sometimes', 'nullable', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
        ];
    }
}
