<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload da intro de voz (feat/voice-intro). Aceita SÓ áudio (mp3/m4a/aac/ogg/wav)
 * mais os formatos de GRAVAÇÃO no navegador (audio/webm do Chrome, audio/mp4 do
 * Safari via MediaRecorder). `mimetypes` valida pelo conteúdo sniffado, mas quem
 * enforça de verdade é o re-encode ffmpeg (VoiceProcessingService): o arquivo
 * servido é um MP3 que NÓS produzimos, não o que veio. Duração (≤20s) é gate do
 * serviço (ffprobe); tamanho (≤5 MB) é aqui.
 */
class StoreVoiceIntroRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'audio' => [
                'required', 'file',
                'mimetypes:audio/mpeg,audio/mp4,audio/aac,audio/ogg,audio/wav,audio/x-wav,audio/webm,audio/x-m4a,audio/flac,audio/x-flac,video/webm',
                'max:'.(int) (config('voice.max_bytes') / 1024), // KB
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'audio.required' => 'Grave ou escolha um áudio para enviar.',
            // video/webm entra na lista porque o MediaRecorder do Chrome às vezes
            // rotula a gravação SÓ-áudio como video/webm; o ffmpeg extrai só o
            // áudio (`-vn`). A mensagem fala em áudio de propósito.
            'audio.mimetypes' => 'O arquivo precisa ser um áudio (MP3, M4A, AAC, OGG ou WAV).',
            'audio.max' => 'O áudio excede o tamanho máximo de 5 MB.',
        ];
    }
}
