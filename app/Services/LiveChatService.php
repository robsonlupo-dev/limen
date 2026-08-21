<?php

namespace App\Services;

use App\Events\LiveChatSent;
use App\Exceptions\LiveChatException;
use App\Models\AuditLog;
use App\Models\LiveChatMessage;
use App\Models\LiveChatMute;
use App\Models\LiveSession;
use App\Models\User;
use App\Support\ChatContentFilter;
use App\Support\FanAlias;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dona única do chat da live (feat/live-room-console) — a live deixou de subir "no
 * escuro". Performer e espectadores conversam em tempo real pelo Reverb (o mesmo
 * canal `live.{slug}` do <LiveOverlay>; o data channel do LiveKit segue desligado).
 *
 * Invariantes:
 *  - O chat é GRÁTIS (não move token — o que monetiza a live é gorjeta/presente).
 *  - O MESMO filtro de conteúdo do chat 1:1 vale aqui (ChatContentFilter): risco
 *    legal e conduta são barrados ANTES de gravar/difundir, e auditados por
 *    repetição (regra em HMAC, nunca o corpo — mesma disciplina do ChatService).
 *  - O membro aparece SÓ por FanAlias label (M.13.10); a performer, pelo stage_name.
 *  - Moderação: a performer silencia clicando numa mensagem; o servidor resolve o
 *    AUTOR (sem expor o handle aos espectadores). Silenciado não envia mais, é
 *    expulso da sala LiveKit e não reautoriza (LiveViewController).
 */
class LiveChatService
{
    public function __construct(private LiveKitService $livekit) {}

    /**
     * Registra e difunde uma mensagem da sala. Grava só depois de passar o filtro
     * (mensagem barrada não vira linha nem broadcast). Membro silenciado é recusado.
     *
     * @throws LiveChatException
     */
    public function send(LiveSession $session, User $sender, bool $isPerformer, string $body): LiveChatMessage
    {
        if (! $isPerformer && $this->isMuted($session, $sender)) {
            throw LiveChatException::muted();
        }

        $this->assertContentAllowed($sender, $body);

        $label = $isPerformer
            ? (string) $session->performerProfile->stage_name
            : FanAlias::label($session->performer_profile_id, $sender->id);

        $message = LiveChatMessage::create([
            'live_session_id' => $session->id,
            'sender_id' => $sender->id,
            'is_performer' => $isPerformer,
            'body' => $body,
        ]);

        LiveChatSent::dispatch($session->performerProfile->slug, $message->id, $label, $body, $isPerformer);

        return $message;
    }

    /**
     * A performer silencia o AUTOR de uma mensagem da SUA sala. Idempotente (silenciar
     * de novo é no-op). Expulsa o membro do LiveKit (best-effort) e o bloqueia no
     * reautorizar (LiveViewController). Mensagem de outra sessão / da própria performer
     * → recusa (nada a silenciar). Devolve o label do silenciado para a confirmação.
     *
     * @throws LiveChatException
     */
    public function mute(LiveSession $session, int $messageId): string
    {
        $message = LiveChatMessage::where('live_session_id', $session->id)
            ->whereKey($messageId)
            ->first();

        // Sem a mensagem, ou é a fala da própria performer: nada a silenciar. O mesmo
        // 409 para os dois — não vira oráculo de qual mensagem existe.
        if ($message === null || $message->is_performer) {
            throw LiveChatException::notLive();
        }

        $memberId = (int) $message->sender_id;

        LiveChatMute::firstOrCreate([
            'live_session_id' => $session->id,
            'member_id' => $memberId,
        ]);

        // Expulsa da sala LiveKit já — o bloqueio no send/refresh cobre a rejoin.
        try {
            $this->livekit->removeParticipant(
                $session->room_name,
                $this->livekit->liveParticipantIdentity($session->id, $memberId),
            );
        } catch (\Throwable) {
            // Rede/LiveKit fora não trava a moderação: a linha de mute já barra o chat
            // e o reautorizar; o token vivo expira no token_ttl. Loga sem o room_name.
            Log::warning('live_chat.remove_participant_failed');
        }

        return FanAlias::label($session->performer_profile_id, $memberId);
    }

    public function isMuted(LiveSession $session, User $member): bool
    {
        return LiveChatMute::where('live_session_id', $session->id)
            ->where('member_id', $member->id)
            ->exists();
    }

    /**
     * Mensagens recentes da sessão (para o carregamento inicial da sala — reload no
     * meio da live). Ordem cronológica; label já mascarado. Limite pequeno: o chat da
     * live é efêmero, não um histórico.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(LiveSession $session, int $limit = 50): array
    {
        $messages = LiveChatMessage::where('live_session_id', $session->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse();

        $stageName = (string) $session->performerProfile->stage_name;

        return $messages->map(fn (LiveChatMessage $m) => [
            'id' => $m->id,
            'label' => $m->is_performer
                ? $stageName
                : FanAlias::label($session->performer_profile_id, (int) $m->sender_id),
            'body' => $m->body,
            'is_performer' => $m->is_performer,
        ])->values()->all();
    }

    /** Apaga o chat e os mutes de uma sessão — chamado no encerramento da live. */
    public function purgeForSession(LiveSession $session): void
    {
        DB::transaction(function () use ($session) {
            LiveChatMessage::where('live_session_id', $session->id)->delete();
            LiveChatMute::where('live_session_id', $session->id)->delete();
        });
    }

    /**
     * Barra a mensagem que casa com a lista de termos proibidos, auditando por
     * repetição (regra em HMAC, corpo NUNCA — cópia do ChatService::auditBlock, que
     * é privado). O resultado depende só do texto, então não vira oráculo de estado.
     *
     * @throws LiveChatException
     */
    private function assertContentAllowed(User $sender, string $body): void
    {
        $match = ChatContentFilter::match($body);

        if ($match === null) {
            return;
        }

        $isConduct = $match['category'] === ChatContentFilter::CONDUCT;

        $this->auditBlock($sender, $body, $match, $isConduct);

        throw $isConduct
            ? LiveChatException::conductBlocked()
            : LiveChatException::contentBlocked();
    }

    /**
     * @param  array{category: string, rule: string}  $match
     */
    private function auditBlock(User $sender, string $body, array $match, bool $isConduct): void
    {
        $ruleHash = ChatContentFilter::digest($match['rule']);
        $minutes = max(1, (int) config('chat_filters.audit_dedup_minutes'));

        // add() atômico: dois envios simultâneos não viram duas linhas.
        if (! Cache::add('livechatfilter:'.$sender->id.':'.substr($ruleHash, 0, 32), true, now()->addMinutes($minutes))) {
            return;
        }

        AuditLog::create([
            'user_id' => $sender->id,
            'action' => 'live_chat.message_blocked',
            'ip' => request()->ip(),
            'metadata' => [
                'category' => $match['category'],
                'rule_hash' => $ruleHash,
                'body_length' => mb_strlen($body),
                'flagged_for_review' => $isConduct,
            ],
        ]);
    }
}
