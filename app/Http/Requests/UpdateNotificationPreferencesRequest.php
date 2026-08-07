<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Um toggle de som por vez (message/tip/live), no mesmo formato do
 * TogglePrivacyPerkRequest: `key` validada por allowlist fechada + `enabled`
 * booleano obrigatório. A allowlist mora no model (User::NOTIFICATION_SOUND_KEYS)
 * — sem ela, o nome do campo viria do request e viraria escrita arbitrária no
 * JSON de preferências.
 */
class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Basta estar autenticado: cada usuário edita a própria preferência, sem
        // exposição de dado de terceiro nem privilégio. O gate é a rota (auth+2fa).
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'key' => ['required', Rule::in(User::NOTIFICATION_SOUND_KEYS)],
            // Obrigatório e não "inverte se ausente": o cliente diz o estado que
            // quer, o que torna duplo clique / retry inofensivos.
            'enabled' => ['required', 'boolean'],
        ];
    }

    public function key(): string
    {
        return $this->validated('key');
    }

    public function desiredValue(): bool
    {
        return filter_var($this->validated('enabled'), FILTER_VALIDATE_BOOLEAN);
    }
}
