<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Web\Concerns\FailsValidationAsJson;
use App\Rules\SafeProfileText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Mensagem do canal de transmissão da performer (roadmap social, Onda 2).
 *
 * `body` passa pelo MESMO filtro público anti-contato da bio/status/enquete
 * (`SafeProfileText`) — um broadcast vai para todos os seguidores de graça, então é
 * o vetor mais perigoso de fuga de contato pago; o filtro é obrigatório aqui. O
 * TETO diário é regra de negócio e vive no `BroadcastService`. Rota web → JSON.
 */
class SendBroadcastRequest extends FormRequest
{
    use FailsValidationAsJson;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Trava no MENOR entre o config e a largura da coluna (VARCHAR 1000): subir
        // BROADCAST_MAX_LENGTH acima disso não pode aceitar um texto que o banco
        // truncaria. Config e coluna caminham juntos.
        $max = min((int) config('broadcast.max_length', 1000), 1000);

        return [
            'body' => ['required', 'string', 'max:'.$max, new SafeProfileText],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'Escreva a mensagem da transmissão.',
            'body.max' => 'A transmissão excede o tamanho máximo de :max caracteres.',
        ];
    }
}
