<?php

use App\Models\Conversation;
use App\Models\PerformerProfile;
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

/*
| live.{slug}: canal da LIVE PÚBLICA da performer (Sprint 15). Carrega o
| <LiveOverlay> — a animação de gorjeta/presente que TODOS na sala veem (prova
| social). Autorizado a quem pode ASSISTIR à live: a própria performer (dona do
| perfil) OU qualquer membro verificado (consumer ativo — a live é pública a todo
| membro). O payload é prova social não-sensível (FanAlias label, valor, tipo);
| ainda assim é canal PRIVADO por defesa em profundidade — só quem entraria na
| live recebe o feed. Perfil inexistente nega (nunca vaza existência).
|
| Por SLUG (público, já no resource do viewer), nunca pelo room_name do LiveKit.
*/
Broadcast::channel('live.{slug}', function (User $user, string $slug) {
    $profile = PerformerProfile::where('slug', $slug)->first();

    if ($profile === null) {
        return false;
    }

    return $profile->user_id === $user->id
        || ($user->role === 'consumer' && $user->status === 'active');
});
