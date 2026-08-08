<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateNotificationPreferencesRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;

/**
 * Preferências de som de notificação (Sprint 16). Porta web (sessão + CSRF),
 * role-NEUTRA de propósito: os três sons alcançam papéis diferentes — `message`
 * ambos, `tip`/`live` a performer —, então o endpoint vive no grupo `auth`+`2fa`
 * compartilhado, não sob `role:consumer`. Cada usuário só toca a própria linha.
 */
class NotificationPreferencesController extends Controller
{
    public function update(UpdateNotificationPreferencesRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Reescreve preservando as demais chaves; Arr::only garante que nada além
        // da allowlist de NOTIFICATION_SOUND_KEYS sobreviva no JSON, mesmo que uma
        // linha antiga tivesse resíduo.
        $prefs = Arr::only(
            $user->notification_preferences ?? [],
            User::NOTIFICATION_SOUND_KEYS,
        );
        $prefs[$request->key()] = $request->desiredValue();

        $user->notification_preferences = $prefs;
        $user->save();

        return back()->with('success', 'Preferência de notificação atualizada');
    }
}
