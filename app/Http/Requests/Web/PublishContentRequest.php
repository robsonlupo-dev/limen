<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use App\Models\PerformerContent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Publicação de conteúdo permanente (M.4). Aceita FOTO (JPEG/PNG, ≤10 MB) OU
 * VÍDEO (MP4/MOV/WebM/MKV, ≤500 MB) — o teto de tamanho é POR TIPO. `mimes`/
 * `mimetypes` validam pelo conteúdo (guess), mas quem enforça de verdade é o
 * re-encode: ImageProcessingService (foto) e VideoProcessingService/ffmpeg
 * (vídeo). Duração do vídeo (≤10 min) é gate do PerformerContentService (ffprobe).
 */
class PublishContentRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true;
    }

    /** É upload de vídeo? Decidido pelo MIME sniffado do arquivo. */
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

        return [
            'arquivo' => $fileRules,
            'nivel' => ['required', 'string', Rule::in(PerformerContent::LEVELS)],
            // min 5 + múltiplo de 5 (M.4); a dona da regra é TokenCreditPolicy.
            'preco' => ['required', 'integer', 'min:5', 'max:100000'],
            // "Com rosto" (Onda B): declaração da performer de que a peça mostra
            // o rosto dela. Opcional; ausente = não.
            'com_rosto' => ['nullable', 'boolean'],
            // Rosto borrado (Onda B): a performer pede para borrar um OVAL que ela
            // posicionou (só foto; vídeo ignora). As coordenadas são normalizadas 0..1
            // e só importam quando `borrar_rosto` está ligado — o controller as ignora
            // senão. Faixas largas (o servidor ainda faz clamp no FaceBlurService).
            'borrar_rosto' => ['nullable', 'boolean'],
            'rosto_cx' => ['nullable', 'numeric', 'between:0,1'],
            'rosto_cy' => ['nullable', 'numeric', 'between:0,1'],
            'rosto_rx' => ['nullable', 'numeric', 'between:0.02,1'],
            'rosto_ry' => ['nullable', 'numeric', 'between:0.02,1'],
        ];
    }

    public function messages(): array
    {
        return [
            'arquivo.required' => 'Escolha uma foto ou um vídeo para publicar.',
            'arquivo.mimes' => 'A imagem precisa ser JPEG ou PNG.',
            'arquivo.mimetypes' => 'O vídeo precisa ser MP4, MOV, WebM ou MKV.',
            'arquivo.max' => 'O arquivo excede o tamanho máximo (10 MB foto / 500 MB vídeo).',
            'nivel.in' => 'Nível de acesso inválido.',
            'preco.min' => 'O preço mínimo é 5 tokens.',
        ];
    }
}
