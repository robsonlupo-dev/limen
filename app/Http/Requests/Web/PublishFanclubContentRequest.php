<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Publicação de uma peça no SET DE FÃ-CLUBE (Onda 4). Igual à publicação de conteúdo
 * (FOTO JPEG/PNG ≤10 MB OU VÍDEO MP4/MOV/WebM/MKV ≤500 MB), mas SEM nível nem preço — o
 * acesso ao set é por assinatura, não por peça. O re-encode (GD/ffmpeg) é quem enforça
 * de verdade; duração do vídeo é gate do PerformerContentService (ffprobe).
 */
class PublishFanclubContentRequest extends FormRequest
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
            ? [
                'required', 'file',
                'mimetypes:video/mp4,video/quicktime,video/webm,video/x-matroska',
                'max:'.(int) (config('video.max_bytes') / 1024), // KB
            ]
            : ['required', 'file', 'mimes:jpeg,png', 'max:10240'];

        return ['arquivo' => $fileRules];
    }

    public function messages(): array
    {
        return [
            'arquivo.required' => 'Escolha uma foto ou um vídeo para o fã-clube.',
            'arquivo.mimes' => 'A imagem precisa ser JPEG ou PNG.',
            'arquivo.mimetypes' => 'O vídeo precisa ser MP4, MOV, WebM ou MKV.',
            'arquivo.max' => 'O arquivo excede o tamanho máximo (10 MB foto / 500 MB vídeo).',
        ];
    }
}
