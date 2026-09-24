<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload de uma mensagem de voz no chat (feat/chat-voice-message). Espelha o
 * StoreVoiceIntroRequest: aceita só áudio (+ os rótulos de gravação do navegador),
 * mas quem enforça de verdade é o re-encode ffmpeg — o arquivo servido é um MP3
 * que NÓS produzimos. Duração (≤120s) é gate do job; tamanho (≤16 MB) é aqui. A
 * policy de participação na conversa é conferida no controller.
 */
class StoreChatAudioRequest extends FormRequest
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
                'max:'.(int) (config('voice.chat_max_bytes') / 1024), // KB
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'audio.required' => 'Grave um áudio para enviar.',
            'audio.mimetypes' => 'O arquivo precisa ser um áudio.',
            'audio.max' => 'O áudio é muito grande. Grave uma mensagem mais curta.',
        ];
    }
}
