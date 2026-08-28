<?php

namespace App\Services;

use App\Events\LiveStateChanged;
use App\Models\CallSession;
use App\Models\LiveSession;
use App\Models\PerformerProfile;
use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Models\User;
use App\Support\TokenMath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dona única do ciclo de vida da live pública (Sprint 15, PR #139). A live é
 * GRÁTIS: aqui não há débito/crédito — só sala LiveKit + estado. Monetização é
 * gorjeta/presente pelas rotas existentes.
 *
 * Invariantes herdadas do #138/plano-revisado:
 *  - Identity do viewer é HMAC por live (LiveKitService::liveParticipantIdentity),
 *    nunca o id cru; sem tabela de participantes (sem watch-list).
 *  - roomName nunca sai em resposta/URL/log — só dentro do JWT. As respostas
 *    devolvem {token, wsUrl}; o livekit-client extrai a sala do próprio token.
 *  - Estado ao vivo é reconciliado NA LEITURA (activeFor): live abandonada
 *    (performer fechou o navegador) vira ended quando a sala já morreu no LiveKit.
 */
class LiveSessionService
{
    public function __construct(
        private LiveKitService $livekit,
        private LivePreviewService $previews,
        private LiveChatService $chat,
    ) {}

    /**
     * Inicia (ou retoma) a live da performer. Idempotente sob o lock do profile:
     * um 2º start concorrente relê "já há live?" e devolve a mesma sala, sem criar
     * uma segunda. Devolve o JWT do publisher (canPublish, sem subscribe/data).
     *
     * @return array{token:string, wsUrl:string}
     */
    public function start(User $performer): array
    {
        $profile = $performer->performerProfile;

        $session = DB::transaction(function () use ($profile) {
            $locked = PerformerProfile::whereKey($profile->getKey())->lockForUpdate()->firstOrFail();

            $session = LiveSession::live()->where('performer_profile_id', $locked->id)->first();
            if ($session) {
                return $session;
            }

            $room = $this->livekit->liveRoomName($locked->id);
            // createRoom é chamada de rede DENTRO do lock: aceitável porque só as
            // requisições da PRÓPRIA performer contendem esta linha, e o start é
            // raro. Se falhar, a transação reverte — nenhuma live_session órfã.
            $this->livekit->createRoom($room, (int) config('livekit.max_participants_live'));

            $session = LiveSession::create([
                'performer_profile_id' => $locked->id,
                'room_name' => $room,
                'status' => 'live',
                'viewer_count' => 0,
                'started_at' => now(),
            ]);

            $locked->forceFill(['is_live' => true])->save();

            return $session;
        });

        return $this->tokenBundle(
            $this->livekit->performerIdentity($session->performer_profile_id),
            LiveKitService::ROLE_PERFORMER,
            $session->room_name,
            ['canPublish' => true, 'canSubscribe' => false, 'canPublishData' => false],
        );
    }

    /** Encerra a live ativa da performer (idempotente: sem live → no-op). */
    public function stop(User $performer): void
    {
        $profile = $performer->performerProfile;

        DB::transaction(function () use ($profile) {
            $locked = PerformerProfile::whereKey($profile->getKey())->lockForUpdate()->firstOrFail();
            $session = LiveSession::live()->where('performer_profile_id', $locked->id)->lockForUpdate()->first();

            if ($session) {
                $this->endSession($session, $locked);
            }
        });
    }

    /**
     * Fecha TODA live ativa de uma performer — gancho de ban/suspensão. Roda
     * DENTRO da transação de quem bane (o UPDATE de estado é atômico com o ban); o
     * deleteRoom é best-effort e nunca derruba o ban (safeDeleteRoom engole a
     * falha de rede). Sem isto, conta encerrada continuaria com sala viva no
     * LiveKit servindo vídeo — o risco do §2.5.
     */
    public function closeForPerformer(PerformerProfile $profile): void
    {
        $sessions = LiveSession::live()->where('performer_profile_id', $profile->id)->get();

        foreach ($sessions as $session) {
            $this->endSession($session, $profile);
        }
    }

    /**
     * A live ativa da performer, RECONCILIADA na leitura: se a sala já morreu no
     * LiveKit (performer sumiu / empty_timeout), encerra a live_session e devolve
     * null — o card não mente. Precedente "expiração vale na leitura" do
     * ChatAccess/Story/Boost aplicado à live (que não tem carimbo de fim natural).
     */
    public function activeFor(PerformerProfile $profile): ?LiveSession
    {
        $session = LiveSession::live()->where('performer_profile_id', $profile->id)->first();
        if (! $session) {
            return null;
        }

        // Rede de segurança da PAUSA (feat/private-call-from-live): pausada mas SEM
        // chamada ativa (a performer caiu/fechou a aba no meio, ou a chamada morreu
        // por ban/reap) → retoma na leitura, para os viewers não ficarem presos no
        // "volta já". Só roda quando está pausada (a consulta extra não pesa no caso
        // comum). Precedente "expiração vale na leitura".
        if ($session->paused_at !== null && ! $this->performerHasActiveCall($profile->id)) {
            $this->clearPause($session, $profile);
            $session->paused_at = null;
        }

        try {
            $roomExists = $this->livekit->roomExists($session->room_name);
        } catch (\Throwable) {
            // LiveKit inacessível: NÃO reconcilia (um soluço de rede não é sinal de
            // sala morta) e assume a live de pé. Fail-open só de disponibilidade e
            // seguro — se o LiveKit está fora, o token não conecta em nada, e o
            // token expira em token_ttl; o ban tem seu próprio caminho (deleteRoom).
            return $session;
        }

        if ($roomExists) {
            return $session;
        }

        // Sala morta: reconcilia sob lock e devolve null.
        DB::transaction(function () use ($session, $profile) {
            $locked = PerformerProfile::whereKey($profile->getKey())->lockForUpdate()->first();
            $fresh = LiveSession::live()->whereKey($session->getKey())->lockForUpdate()->first();
            if ($fresh) {
                $fresh->forceFill(['status' => 'ended', 'ended_at' => now()])->save();
            }
            if ($locked) {
                $locked->forceFill(['is_live' => false])->save();
            }
        });

        // Live abandonada (sala morreu no LiveKit) reconciliada na leitura: o frame
        // e o chat não passam por endSession, então apaga aqui. O `live-previews:purge`
        // é a rede de segurança para o que escapar.
        $this->previews->delete($session->id);
        $this->chat->purgeForSession($session);

        return null;
    }

    // ── Pausa por chamada privada (feat/private-call-from-live) ──────────────────

    /**
     * PAUSA a live ativa da performer (ela aceitou uma chamada privada). Sub-estado:
     * a sessão segue `status='live'` (viewers NÃO caem em 410), só ganha `paused_at`.
     * Idempotente (já pausada → no-op) e só pausa se há de fato uma chamada 1:1 ATIVA
     * dela — sem isso não há por que pausar, e a rede de segurança do `activeFor`
     * retomaria na leitura seguinte. Difunde `LiveStateChanged(paused=true)` para os
     * espectadores mostrarem "volta já" em tempo real.
     */
    public function pause(User $performer): void
    {
        $profile = $performer->performerProfile;
        if ($profile === null) {
            return;
        }

        $changed = DB::transaction(function () use ($profile) {
            $session = LiveSession::live()
                ->where('performer_profile_id', $profile->id)
                ->lockForUpdate()
                ->first();

            if ($session === null || $session->paused_at !== null) {
                return false;
            }
            if (! $this->performerHasActiveCall($profile->id)) {
                return false;
            }

            $session->forceFill(['paused_at' => now()])->save();

            return true;
        });

        if ($changed) {
            LiveStateChanged::dispatch($profile->slug, true);
        }
    }

    /** RETOMA a live (chamada encerrada). Idempotente (não pausada → no-op). */
    public function resume(User $performer): void
    {
        $profile = $performer->performerProfile;
        if ($profile === null) {
            return;
        }

        $session = LiveSession::live()->where('performer_profile_id', $profile->id)->first();
        if ($session !== null) {
            $this->clearPause($session, $profile);
        }
    }

    /** Há uma chamada 1:1 ATIVA desta performer? (gate da pausa e da rede de segurança) */
    private function performerHasActiveCall(int $performerProfileId): bool
    {
        return CallSession::where('performer_profile_id', $performerProfileId)
            ->whereNotNull('member_id')
            ->active()
            ->exists();
    }

    /** Limpa `paused_at` sob lock (re-checa para não difundir "retomou" duas vezes). */
    private function clearPause(LiveSession $session, PerformerProfile $profile): void
    {
        $changed = DB::transaction(function () use ($session) {
            $fresh = LiveSession::whereKey($session->getKey())->lockForUpdate()->first();
            if ($fresh === null || $fresh->paused_at === null) {
                return false;
            }
            $fresh->forceFill(['paused_at' => null])->save();

            return true;
        });

        if ($changed) {
            LiveStateChanged::dispatch($profile->slug, false);
        }
    }

    /**
     * JWT do MEMBRO para assistir. Identity HMAC estável-por-live; view-only
     * (canSubscribe), sem publish e sem data channel — o canPublishData:false
     * fecha por design qualquer chat de texto P2P não moderado (adiado, ver PR).
     *
     * @return array{token:string, wsUrl:string}
     */
    public function memberToken(LiveSession $session, User $member): array
    {
        return $this->tokenBundle(
            $this->livekit->liveParticipantIdentity($session->id, $member->id),
            LiveKitService::ROLE_MEMBER,
            $session->room_name,
            ['canPublish' => false, 'canSubscribe' => true, 'canPublishData' => false],
        );
    }

    /**
     * Contagem aproximada de espectadores (social proof), via listParticipants
     * cacheado ~12s — a dona única do número EXIBIDO ao vivo. Desconta o publisher
     * (a performer). Falha de rede → 0. Nunca em faixa: é agregado da audiência,
     * não exposição de indivíduo.
     */
    public function viewerCount(LiveSession $session): int
    {
        return Cache::remember("live:{$session->id}:viewers", 12, function () use ($session) {
            try {
                return max(0, count($this->livekit->listParticipants($session->room_name)) - 1);
            } catch (\Throwable) {
                return 0;
            }
        });
    }

    /**
     * Ganho ACUMULADO nesta transmissão — o que o console da performer mostra em
     * tempo real (feat/live-room-console). Soma os créditos de GANHO da live (gorjeta
     * + presente) da carteira dela desde `started_at`. Lê o DECIMAL cru do banco
     * (SUM ignora o accessor) e devolve pelo contrato uniforme de token
     * (TokenMath::readable: int quando inteiro, string 4dp quando fracionário) — bate
     * com o ledger por construção, sem recalcular split. A live em si é grátis (sem
     * live_credit); só gorjeta/presente creditam.
     */
    public function earnedThisLive(LiveSession $session): int|string
    {
        $walletId = TokenWallet::where('user_id', $session->performerProfile->user_id)->value('id');

        if ($walletId === null) {
            return 0;
        }

        $sum = TokenLedger::where('wallet_id', $walletId)
            ->whereIn('entry_type', ['tip_credit', 'gift_credit'])
            ->where('created_at', '>=', $session->started_at)
            ->sum('amount');

        return TokenMath::readable(TokenMath::of((string) ($sum ?: '0')));
    }

    private function endSession(LiveSession $session, PerformerProfile $profile): void
    {
        // Snapshot histórico do ÚLTIMO valor cacheado — sem chamada de rede DENTRO
        // da transação de stop/ban (a listParticipants ao vivo fica só na página).
        $viewers = (int) Cache::get("live:{$session->id}:viewers", 0);

        $session->forceFill([
            'status' => 'ended',
            'ended_at' => now(),
            'viewer_count' => $viewers, // snapshot histórico, não o número ao vivo
        ])->save();

        $profile->forceFill(['is_live' => false])->save();

        $this->safeDeleteRoom($session->room_name);

        // Frame de preview do catálogo morre com a live (PR #143). Roda no
        // stop E no ban (ambos passam por aqui). O órfão que escapar — live
        // reconciliada na leitura sem passar por endSession — é varrido pelo
        // command `live-previews:purge` (1h por mtime).
        $this->previews->delete($session->id);

        // O chat da live é efêmero: some com a transmissão (não é histórico).
        $this->chat->purgeForSession($session);
    }

    private function safeDeleteRoom(string $room): void
    {
        try {
            $this->livekit->deleteRoom($room);
        } catch (\Throwable) {
            // Rede/LiveKit fora não pode travar stop/ban. A live_session já está
            // ended (gate autoritativo: join/refresh dão 404/410), e os tokens
            // vivos expiram no token_ttl. Loga sem o room_name (invariante).
            Log::warning('live.delete_room_failed');
        }
    }

    /** @return array{token:string, wsUrl:string} */
    private function tokenBundle(string $identity, string $role, string $room, array $grants): array
    {
        return [
            'token' => $this->livekit->generateToken($identity, $role, $room, $grants),
            'wsUrl' => (string) config('livekit.url'),
        ];
    }
}
