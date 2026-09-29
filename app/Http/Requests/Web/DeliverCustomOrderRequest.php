<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Entrega de uma encomenda sob medida (Onda 4 §4.3): a performer sobe a peça (FOTO
 * JPEG/PNG ≤10 MB OU VÍDEO MP4/MOV/WebM/MKV ≤500 MB), como no PublishContentRequest. O
 * re-encode/CSAM/ffmpeg é a defesa real; aqui só o gate de tipo/tamanho. A autorização
 * (é a performer dona da encomenda, no estado certo) fica no CustomOrderService.
 */
class DeliverCustomOrderRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true;
    }

    public function isVideoUpload(): bool
    {
        $file = $this->file('arquivo');

        return $file !== null && str_starts_with((string) $file->getMimeType(), 'video/');
    }

    public function rules(): array
    {
        $fileRules = $this->isVideoUpload()
            ? ['required', 'file', 'mimetypes:video/mp4,video/quicktime,video/webm,video/x-matroska', 'max:'.(int) (config('video.max_bytes') / 1024)]
            : ['required', 'file', 'mimes:jpeg,png', 'max:10240'];

        return ['arquivo' => $fileRules];
    }

    public function messages(): array
    {
        return [
            'arquivo.required' => 'Escolha a foto ou o vídeo da encomenda.',
            'arquivo.mimes' => 'A imagem precisa ser JPEG ou PNG.',
            'arquivo.mimetypes' => 'O vídeo precisa ser MP4, MOV, WebM ou MKV.',
            'arquivo.max' => 'O arquivo excede o tamanho máximo (10 MB foto / 500 MB vídeo).',
        ];
    }
}
