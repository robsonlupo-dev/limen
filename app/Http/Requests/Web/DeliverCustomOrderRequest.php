<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use App\Rules\SafeProfileText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Entrega de uma encomenda sob medida (Onda 4 §4.3): a performer sobe a peça (FOTO
 * JPEG/PNG ≤10 MB OU VÍDEO MP4/MOV/WebM/MKV ≤500 MB), como no PublishContentRequest, e
 * pode escrever um RECADO opcional (`mensagem`) que o membro lê junto da entrega. O
 * re-encode/CSAM/ffmpeg é a defesa real da mídia; o recado passa pelo mesmo filtro
 * anti-contato (SafeProfileText) da descrição do pedido — é mais um vetor de fuga de
 * contato grátis. A autorização (é a performer dona da encomenda, no estado certo) fica
 * no CustomOrderService.
 */
class DeliverCustomOrderRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true;
    }

    /** Normaliza o recado antes de validar, para o `max` medir o texto já aparado. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('mensagem'))) {
            $this->merge(['mensagem' => trim($this->input('mensagem'))]);
        }
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

        return [
            'arquivo' => $fileRules,
            'mensagem' => ['nullable', 'string', 'max:'.(int) config('custom_order.delivery_message_max_length', 500), new SafeProfileText],
        ];
    }

    public function messages(): array
    {
        return [
            'arquivo.required' => 'Escolha a foto ou o vídeo da encomenda.',
            'arquivo.mimes' => 'A imagem precisa ser JPEG ou PNG.',
            'arquivo.mimetypes' => 'O vídeo precisa ser MP4, MOV, WebM ou MKV.',
            'arquivo.max' => 'O arquivo excede o tamanho máximo (10 MB foto / 500 MB vídeo).',
            'mensagem.max' => 'O recado é muito longo.',
        ];
    }
}
