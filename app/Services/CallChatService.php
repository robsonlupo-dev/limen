<?php

namespace App\Services;

use App\Events\CallMessageSent;
use App\Exceptions\ChatException;
use App\Models\AuditLog;
use App\Models\CallMessage;
use App\Models\CallSession;
use App\Models\User;
use App\Support\ChatContentFilter;
use App\Support\ClientFingerprint;
use App\Support\FanAlias;
use Illuminate\Support\Facades\Cache;

/**
 * Chat de TEXTO da chamada 1:1 (feat/in-call-chat). Mesma política do chat pago:
 * o ChatContentFilter barra risco legal (intermediação de encontro pago, TIPO 1)
 * e conduta (ameaça/insulto direcionado, TIPO 2); troca de contato PASSA (o par
 * já se vê e se fala por vídeo — bloquear o texto seria teatro). O corpo barrado
 * NUNCA vai para o audit; só a regra em HMAC, deduplicada — igual ao ChatService.
 *
 * Privacidade (M.13.10): a performer vê o membro só pelo FanAlias; nenhum payload
 * carrega member_id/sender_id/tier/saldo.
 */
class CallChatService
{
    /** Teto do corpo (o PO escolheu 500 para a chamada; o chat normal usa 1000). */
    public const MAX_LENGTH = 500;

    /** Quantas mensagens o painel carrega ao abrir. */
    private const HISTORY_LIMIT = 50;

    /**
     * Grava e difunde uma mensagem. Pressupõe que o chamador já autorizou o
     * remetente como participante e que a chamada está ativa.
     *
     * @return array<string, mixed>  payload de exibição (id, label, body, is_performer)
     *
     * @throws ChatException  quando o filtro barra o conteúdo
     */
    public function send(CallSession $call, User $sender, string $body): array
    {
        $this->assertContentAllowed($sender, $body);

        $message = CallMessage::create([
            'call_session_id' => $call->id,
            'sender_id' => $sender->id,
            'body' => $body,
        ]);

        $isPerformer = $this->isPerformer($call, $sender->id);
        $payload = [
            'id' => $message->id,
            'label' => $this->labelFor($call, $sender->id, $isPerformer),
            'body' => $body,
            'is_performer' => $isPerformer,
        ];

        CallMessageSent::dispatch($call->id, $message->id, $payload['label'], $body, $isPerformer);

        return $payload;
    }

    /**
     * Histórico recente para o painel (ordem cronológica). Cada item já vem com o
     * label de exibição resolvido pelo lado de quem enviou.
     *
     * @return array<int, array<string, mixed>>
     */
    public function history(CallSession $call): array
    {
        return CallMessage::where('call_session_id', $call->id)
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->map(function (CallMessage $m) use ($call) {
                $isPerformer = $this->isPerformer($call, $m->sender_id);

                return [
                    'id' => $m->id,
                    'label' => $this->labelFor($call, $m->sender_id, $isPerformer),
                    'body' => $m->body,
                    'is_performer' => $isPerformer,
                ];
            })
            ->values()
            ->all();
    }

    private function isPerformer(CallSession $call, int $senderId): bool
    {
        return $call->performerProfile?->user_id === $senderId;
    }

    /**
     * Label de EXIBIÇÃO: a performer aparece pelo stage_name; o membro, pelo
     * FanAlias do par (nunca o member_id). Mesma disciplina do LiveChatSent.
     */
    private function labelFor(CallSession $call, int $senderId, bool $isPerformer): string
    {
        if ($isPerformer) {
            return (string) $call->performerProfile?->stage_name;
        }

        return FanAlias::label($call->performer_profile_id, $call->member_id);
    }

    /**
     * Espelha ChatService::assertContentAllowed — barra TIPO 1 (legal) e TIPO 2
     * (conduta), audita a REGRA em HMAC (nunca o corpo), deduplicado por janela.
     *
     * @throws ChatException
     */
    private function assertContentAllowed(User $sender, string $body): void
    {
        $match = ChatContentFilter::match($body);

        if ($match === null) {
            return;
        }

        $isConduct = $match['category'] === ChatContentFilter::CONDUCT;
        $this->auditBlock($sender, $match);

        throw $isConduct
            ? ChatException::conductBlocked()
            : ChatException::legalRiskBlocked();
    }

    /**
     * @param  array{category: string, rule: string}  $match
     */
    private function auditBlock(User $sender, array $match): void
    {
        $ruleHash = ChatContentFilter::digest($match['rule']);
        $minutes = max(1, (int) config('chat_filters.audit_dedup_minutes'));

        // add() é atômico: dois envios simultâneos não viram duas linhas.
        if (! Cache::add('callchatfilter:'.$sender->id.':'.substr($ruleHash, 0, 32), true, now()->addMinutes($minutes))) {
            return;
        }

        AuditLog::create([
            'user_id' => $sender->id,
            'action' => 'call_chat.message_blocked',
            'ip_hash' => ClientFingerprint::hash(request()->ip()),
            'metadata' => [
                'category' => $match['category'],
                'rule_hash' => $ruleHash,
            ],
        ]);
    }
}
