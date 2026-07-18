<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Autorização dos canais privados. O transporte em produção será o Reverb, mas
| a autenticação do canal usa illuminate/broadcasting (já presente) e a sessão
| web — é agnóstica de driver.
|
| conversation.{id}: só os DOIS participantes (o membro dono e o usuário da
| performer) recebem as mensagens. Qualquer outro usuário autenticado é negado.
| Nunca vaza a existência da conversa: um id inexistente ou de terceiro nega.
|
*/

Broadcast::channel('conversation.{conversation}', function (User $user, int $conversation) {
    $model = Conversation::with('performerProfile')->find($conversation);

    return $model !== null && $model->hasParticipant($user);
});

/*
| user.{id}: canal PESSOAL do usuário. A lista de conversas (Chat/Index) o assina
| p/ atualizar preview/badge/timestamp em tempo real (evento NewMessage). Cada um
| só assina o próprio id — nunca o de outro.
*/
Broadcast::channel('user.{id}', function (User $user, int $id) {
    return $user->id === $id;
});
