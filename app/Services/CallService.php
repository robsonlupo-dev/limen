<?php

namespace App\Services;

use App\Events\CallAccepted;
use App\Support\TokenMath;
use App\Events\CallDeclined;
use App\Events\CallEnded;
use App\Events\CallRequested;
use App\Exceptions\CallException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\CallSession;
use App\Models\CallSessionParticipant;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Support\FanAlias;
use App\Support\MinuteBiller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dona ÚNICA do ciclo de vida E da cobrança da chamada privada 1:1 (Sprint 15,
 * PR #140). Nenhuma outra classe cobra minuto de chamada ou cria/derruba a sala
 * LiveKit da chamada. Usa TokenService (débito bruto), TokenCreditPolicy (split
 * 70/30 congelado) e LiveKitService (sala + JWT).
 *
 * ── Cobrança pré-paga por minuto, saldo NUNCA negativo ───────────────────────
 * Para estar conectado no segundo `t`, o membro precisa já ter pago o minuto
 * `floor(t/60)+1`. `call_sessions.minutes_billed` é a FONTE ÚNICA de idempotência:
 * `reconcileBilling()` (o ÚNICO caminho de cobrança, chamado por heartbeat E
 * token-refresh) roda SEMPRE sob `CallSession::lockForUpdate()`, cobra enquanto
 * `minutes_billed < required` e incrementa atômico com o débito. Dois heartbeats
 * no mesmo minuto → `required` igual → no-op. `chargeMinute` só roda com
 * `saldo >= price`, e o `TokenService::debit` re-checa sob o lock do wallet: saldo
 * < 0 é impossível (achados 🔴 1 e 3 da revisão do plano).
 *
 * ⚠️ RESSALVA CONHECIDA — carona de até um TTL de token (~5 min). A cobrança é
 * PULL: só acontece quando o cliente do MEMBRO chama heartbeat/token-refresh (não
 * há webhook do LiveKit nem tick server-side). Um membro que suprime OS DOIS fica
 * conectado até o token expirar (livekit.token_ttl=300s) sem pagar os minutos 2–5;
 * `end` também não reconcilia (quem sonega não chama /end). O que fecha durações
 * ALÉM de um TTL é a renovação obrigatória (refreshToken reconcilia e recusa+
 * derruba se não paga). A carona é BOUNDED por um TTL e de alta fricção (nova
 * sessão + re-accept da performer a cada ~5 min). NÃO descrever como "impossível
 * não pagar": o fecho definitivo é um webhook `participant_disconnected` (track de
 * infra LiveKit). Achado 🟡 da revisão de código, registrado e aceito.
 *
 * ── Anonimato (FanAlias / M.13.10) ───────────────────────────────────────────
 * A performer nunca vê member_id, tier nem saldo. A identity da sala é o FanAlias
 * handle por par; os broadcasts levam o FanAlias LABEL (4 dígitos), nunca o id nem
 * o handle. O crédito da performer descreve por FanAlias.
 */
class CallService
{
    public function __construct(
        private TokenService $tokenService,
        private TokenCreditPolicy $creditPolicy,
        private LiveKitService $livekit,
        private MinuteBiller $minuteBiller,
    ) {}

    // ── Request (membro) ──────────────────────────────────────────────────────

    /**
     * O membro pede uma chamada. Cria a linha `pending` (sala LiveKit só nasce no
     * accept). Valida saldo do 1º minuto, performer verificada+ativa+aceitando
     * chamadas, e — sob o lock do perfil — que nem a performer nem o membro já
     * estão em outra chamada (409). Broadcast CallRequested no canal da performer.
     */
    public function request(User $member, PerformerProfile $profile): CallSession
    {
        // Auto-chamada não faz sentido; 404 uniforme (não confirma nada sobre o par).
        if ($member->id === $profile->user_id) {
            throw CallException::notFound();
        }

        $performerUser = $profile->user;
        if (! $profile->is_verified
            || $performerUser === null
            || $performerUser->status !== 'active'
            || $profile->call_price_per_minute === null) {
            throw CallException::unavailable();
        }

        $price = (int) $profile->call_price_per_minute;

        if (TokenMath::cmp($this->tokenService->balance($member), $price) < 0) {
            throw CallException::insufficientBalance();
        }

        $session = DB::transaction(function () use ($member, $profile, $price) {
            // Trava a linha do perfil: serializa requests concorrentes à MESMA
            // performer (o 2º relê "já ocupada?" e recusa — o 409 do double-submit).
            $lockedProfile = PerformerProfile::whereKey($profile->id)->lockForUpdate()->firstOrFail();

            // Um pending vencido (>60s) não pode ocupar a performer — reconcilia na
            // leitura antes de checar ocupação (precedente stories:purge/ChatAccess).
            $this->expireStalePendingForPerformer($lockedProfile->id);

            if (CallSession::where('performer_profile_id', $lockedProfile->id)->occupying()->exists()) {
                throw CallException::conflict();
            }
            // Exclusividade do membro: nem outra 1:1 pending/active NEM participação
            // ativa em group show (PR #141 — o 1:1 não pode ser cego ao group, senão
            // dois streams de vídeo cobrados em paralelo).
            if (self::memberIsBusy($member->id)) {
                throw CallException::conflict();
            }

            $session = new CallSession([
                'performer_profile_id' => $lockedProfile->id,
                'member_id' => $member->id,
                'room_name' => $this->livekit->callRoomName(),
                'price_per_minute' => $price,
                'max_duration_minutes' => $lockedProfile->call_max_duration_minutes,
            ]);
            // `type` FORA do $fillable (achado 🔴 2): a constante do service, nunca
            // do request — senão um POST com type=group furaria o teto de 2.
            $session->forceFill(['type' => CallSession::TYPE_PRIVATE])->save();

            return $session;
        });

        CallRequested::dispatch(
            $performerUser->id,
            $session->id,
            FanAlias::label($profile->id, $member->id),
            $price,
            CallSession::PENDING_TTL_SECONDS,
        );

        return $session;
    }

    // ── Accept / Decline (performer) ──────────────────────────────────────────

    /**
     * A performer aceita. Sob o lock da sessão: relê status/expiração (não confia
     * na leitura pré-lock — achado 🟡 4), cobra o 1º minuto ANTES do createRoom (o
     * rollback limpa o débito se a sala falhar → sem sala órfã cobrada), cria a
     * sala (max 2) e ativa. Devolve o JWT bidirecional da performer.
     *
     * @return array{token:string, wsUrl:string}
     */
    public function accept(User $performerUser, CallSession $session): array
    {
        $result = DB::transaction(function () use ($performerUser, $session) {
            $profile = $performerUser->performerProfile;

            $locked = CallSession::whereKey($session->id)->lockForUpdate()->first();
            if ($locked === null || $profile === null || $locked->performer_profile_id !== $profile->id) {
                throw CallException::notFound();
            }
            if ($locked->pendingExpired()) {
                // Expira e COMMITA (retorna, não joga — jogar reverteria o expired).
                $locked->forceFill(['status' => 'expired'])->save();

                return ['expired' => true];
            }
            if (! $locked->isPending()) {
                throw CallException::notFound();
            }

            $member = $locked->member;
            if ($member === null || $member->status !== 'active') {
                throw CallException::gone();
            }

            // Exclusividade 1:1 do MEMBRO sob concorrência: dois request simultâneos
            // do mesmo membro para performers DIFERENTES travam perfis distintos e
            // podem criar dois pending; sem esta guarda, os dois accept ativariam
            // duas chamadas cobradas em paralelo. Ancoramos no `users` do membro
            // (lockForUpdate) — dois accept do mesmo membro serializam aqui, e o 2º
            // relê "já ativo?" e recusa ANTES de cobrar/criar sala. Fecha o furo
            // 🟡 da revisão de código.
            User::whereKey($member->id)->lockForUpdate()->first();
            $memberBusy = CallSession::where('member_id', $member->id)
                ->active()
                ->whereKeyNot($locked->id)
                ->exists()
                // Também barra quem já está num group show ativo (PR #141).
                || CallSessionParticipant::where('member_id', $member->id)->active()->exists();
            if ($memberBusy) {
                throw CallException::conflict();
            }

            // 1º minuto pré-pago ANTES da sala. InsufficientBalance vira 422 e o
            // rollback desfaz qualquer débito parcial (sem sala, sem cobrança).
            if (TokenMath::cmp($this->tokenService->balance($member), $locked->price_per_minute) < 0) {
                throw CallException::insufficientBalance();
            }
            try {
                $this->chargeMinute($locked, $member, $performerUser);
            } catch (InsufficientBalanceException) {
                throw CallException::insufficientBalance();
            }

            // createRoom é chamada de rede DENTRO da transação (padrão do
            // LiveSessionService::start): se falhar, reverte o débito do 1º minuto.
            $this->livekit->createRoom($locked->room_name, (int) config('livekit.max_participants_call'));

            $locked->forceFill([
                'status' => 'active',
                'started_at' => now(),
                'minutes_billed' => 1,
            ])->save();

            return ['session' => $locked, 'memberUserId' => $member->id];
        });

        if ($result['expired'] ?? false) {
            throw CallException::gone();
        }

        CallAccepted::dispatch($result['memberUserId'], $result['session']->id);

        return $this->tokenBundle(
            $this->livekit->performerIdentity($result['session']->performer_profile_id),
            LiveKitService::ROLE_PERFORMER,
            $result['session']->room_name,
        );
    }

    /** A performer recusa. Marca declined e avisa o membro. */
    public function decline(User $performerUser, CallSession $session): void
    {
        $result = DB::transaction(function () use ($performerUser, $session) {
            $profile = $performerUser->performerProfile;

            $locked = CallSession::whereKey($session->id)->lockForUpdate()->first();
            if ($locked === null || $profile === null || $locked->performer_profile_id !== $profile->id) {
                throw CallException::notFound();
            }
            if ($locked->pendingExpired()) {
                // Expira e COMMITA (retorna, não joga — jogar reverteria o expired).
                $locked->forceFill(['status' => 'expired'])->save();

                return ['expired' => true];
            }
            if (! $locked->isPending()) {
                throw CallException::notFound();
            }

            $locked->forceFill(['status' => 'declined'])->save();

            return ['expired' => false, 'memberId' => $locked->member_id];
        });

        if ($result['expired'] ?? false) {
            throw CallException::gone();
        }

        CallDeclined::dispatch($result['memberId'], $session->id);
    }

    // ── Heartbeat (membro) ────────────────────────────────────────────────────

    /**
     * Batimento a cada 60s do FRONT do membro: cobra os minutos devidos e devolve
     * o estado de saldo para os banners de encerramento. Idempotente por minuto.
     * Quando não dá para sustentar (saldo/teto), marca ended (gate autoritativo) e
     * o front mostra a despedida por 10s antes de chamar /end. Sessão de terceiro
     * ou inexistente → 404; encerrada → 410.
     *
     * @return array{minutes_elapsed:int, balance_remaining:int, minutes_left:int, can_continue:bool}
     */
    public function heartbeat(User $member, CallSession $session): array
    {
        $outcome = DB::transaction(function () use ($member, $session) {
            $locked = CallSession::whereKey($session->id)->lockForUpdate()->first();
            if ($locked === null || $locked->member_id !== $member->id) {
                throw CallException::notFound();
            }
            if (! $locked->isActive()) {
                throw CallException::gone();
            }

            $billing = $this->reconcileBilling($locked, $member);

            return [
                'session' => $locked,
                'billing' => $billing,
            ];
        });

        if ($outcome['billing']['can_continue'] === false) {
            // Encerrada por saldo/teto: avisa o membro (motivo real) e a performer
            // (mensagem neutra — M.13.10). A sala fica de pé para a despedida de 10s.
            $this->dispatchEnded($outcome['session'], $outcome['billing']['ended_reason'], notifyMember: true, notifyPerformer: true);
        }

        return [
            'minutes_elapsed' => $outcome['session']->minutes_billed,
            'balance_remaining' => $outcome['billing']['balance'],
            'minutes_left' => $outcome['billing']['minutes_left'],
            'can_continue' => $outcome['billing']['can_continue'],
        ];
    }

    // ── Token refresh (membro OU performer) ───────────────────────────────────

    /**
     * Renova o JWT (a cada ~4min, antes do TTL de 5). Re-verifica na leitura:
     * sessão ativa + os dois lados de pé. Quando o ator é o MEMBRO, reconcilia a
     * cobrança pelo MESMO reconcileBilling — para passar do TTL o membro TEM que
     * renovar, e a renovação cobra os minutos devidos; se não paga, encerra AGORA
     * (sala apagada) e devolve 410. Isso fecha a carona para durações ALÉM de um
     * TTL; DENTRO de um TTL a carona residual persiste (ver ressalva no docblock
     * da classe). Performer banida/suspensa no meio → 410.
     *
     * @return array{token:string, wsUrl:string}
     */
    public function refreshToken(User $actor, CallSession $session): array
    {
        $state = DB::transaction(function () use ($actor, $session) {
            $locked = CallSession::whereKey($session->id)->lockForUpdate()->first();
            if ($locked === null) {
                throw CallException::notFound();
            }
            // Rota 1:1 só serve sessão 1:1 (member_id não-nulo). Uma sessão group ou
            // group-upgradada-para-private tem member_id NULL e é servida SÓ pelo
            // GroupShowService — servir aqui furaria o lifecycle dela (achado 🔴 da
            // revisão de código: performer renovando a própria group pela porta 1:1).
            if ($locked->member_id === null) {
                throw CallException::notFound();
            }
            $isMember = $locked->member_id === $actor->id;
            $isPerformer = $locked->performerProfile?->user_id === $actor->id;
            if (! $isMember && ! $isPerformer) {
                throw CallException::notFound();
            }
            if (! $locked->isActive()) {
                throw CallException::gone();
            }

            // Os dois lados precisam estar de pé a cada renovação (ban/suspensão no
            // meio → recusa a reautorização). O membro é o próprio $actor quando
            // isMember; a performer é o dono do perfil.
            $performerUser = $locked->performerProfile?->user;
            if ($performerUser === null || $performerUser->status !== 'active' || $actor->status !== 'active') {
                throw CallException::gone();
            }

            if ($isMember) {
                $billing = $this->reconcileBilling($locked, $actor);
                if ($billing['can_continue'] === false) {
                    // Commit da cobrança + do ended; o teardown/410 acontece FORA
                    // da transação (não jogar exceção aqui — reverteria o débito).
                    return ['ended' => true, 'session' => $locked, 'reason' => $billing['ended_reason']];
                }
            }

            $identity = $isMember
                ? $this->livekit->callMemberIdentity($locked->performer_profile_id, $actor->id)
                : $this->livekit->performerIdentity($locked->performer_profile_id);
            $role = $isMember ? LiveKitService::ROLE_MEMBER : LiveKitService::ROLE_PERFORMER;

            return [
                'ended' => false,
                'bundle' => $this->tokenBundle($identity, $role, $locked->room_name),
            ];
        });

        if ($state['ended']) {
            // Carona fechada: derruba a sala AGORA (deleteRoom ejeta os dois) e avisa.
            $this->safeDeleteRoom($state['session']->room_name);
            $this->dispatchEnded($state['session'], $state['reason'], notifyMember: true, notifyPerformer: true);
            throw CallException::gone();
        }

        return $state['bundle'];
    }

    // ── End (qualquer lado) ───────────────────────────────────────────────────

    /**
     * Encerramento voluntário por qualquer participante. Deleta a sala, marca
     * ended. Idempotente (chamar de novo é no-op de sucesso). Avisa o OUTRO lado.
     */
    public function end(User $actor, CallSession $session): void
    {
        $result = DB::transaction(function () use ($actor, $session) {
            $locked = CallSession::whereKey($session->id)->lockForUpdate()->first();
            if ($locked === null) {
                throw CallException::notFound();
            }
            // Rota 1:1 só encerra sessão 1:1 (member_id não-nulo). Group (member_id
            // NULL) é encerrado só pelo GroupShowService::stop — sem isso, a performer
            // encerraria a própria group pela porta 1:1, deixando participantes
            // órfãos `active` e disparando CallEnded p/ member_id=null (500). 🔴 revisão.
            if ($locked->member_id === null) {
                throw CallException::notFound();
            }
            $isMember = $locked->member_id === $actor->id;
            $isPerformer = $locked->performerProfile?->user_id === $actor->id;
            if (! $isMember && ! $isPerformer) {
                throw CallException::notFound();
            }

            // Já encerrada/recusada/expirada → no-op idempotente.
            if (! $locked->isActive() && ! $locked->isPending()) {
                return ['already' => true];
            }

            $wasActive = $locked->isActive();
            $locked->forceFill(['status' => 'ended', 'ended_at' => now()])->save();

            return [
                'already' => false,
                'wasActive' => $wasActive,
                'session' => $locked,
                'isMember' => $isMember,
            ];
        });

        if ($result['already'] ?? false) {
            return;
        }

        if ($result['wasActive']) {
            $this->safeDeleteRoom($result['session']->room_name);
        }

        // Avisa só o OUTRO lado. Quem encerrou já sabe. A performer recebe sempre
        // 'ended' neutro (M.13.10); o membro recebe 'performer_ended'.
        if ($result['isMember']) {
            $this->dispatchEnded($result['session'], 'member_ended', notifyMember: false, notifyPerformer: true);
        } else {
            $this->dispatchEnded($result['session'], 'performer_ended', notifyMember: true, notifyPerformer: false);
        }
    }

    // ── Ban / kill-switch ─────────────────────────────────────────────────────

    /**
     * Fecha TODA chamada pending/active de uma performer — gancho de ban/suspensão,
     * roda DENTRO da transação de quem bane (o UPDATE de estado é atômico com o
     * ban). O deleteRoom é best-effort (não reverte o ban). Pending não tem sala
     * (nasce no accept), então é só marcação de status (achado 🟡 8). Sem broadcast
     * aqui de propósito: a próxima leitura do membro (heartbeat/refresh) dá 410, e
     * o deleteRoom já o ejetou — evita broadcast espúrio se o ban reverter.
     */
    public function closeForPerformer(PerformerProfile $profile): void
    {
        $sessions = CallSession::where('performer_profile_id', $profile->id)->occupying()->get();

        foreach ($sessions as $session) {
            $wasActive = $session->isActive();

            // Group show: encerrar a sessão sozinha deixaria participantes `active`
            // órfãos no banco (achado 🔴 C2). Marca TODOS os participantes ativos
            // ended na MESMA transação do ban antes de derrubar a sala.
            if ($session->isGroup()) {
                CallSessionParticipant::where('call_session_id', $session->id)
                    ->active()
                    ->update(['status' => 'ended', 'ended_at' => now()]);
            }

            $session->forceFill(['status' => 'ended', 'ended_at' => now()])->save();

            if ($wasActive) {
                $this->safeDeleteRoom($session->room_name);
            }
        }
    }

    /**
     * Gancho de ban de QUALQUER usuário (não só performer): encerra o que o banido
     * tem como MEMBRO — a participação ativa em group show (revoke da sala) e as
     * sessões 1:1 ativas dele. Roda na transação do ban. No-op para quem não tem
     * nada. Fecha o 🟢 do #140 (ban de membro no meio de uma chamada) e o requisito
     * do #141 (membro banido em group → só ele sai).
     */
    public function closeForMember(User $member): void
    {
        // Participações ativas em groups: revoga do LiveKit (a sala segue viva para
        // os demais) e marca ended. Identity = FanAlias handle por par.
        $participations = CallSessionParticipant::where('member_id', $member->id)
            ->active()
            ->get();

        foreach ($participations as $participation) {
            $session = CallSession::find($participation->call_session_id);
            $participation->forceFill(['status' => 'ended', 'ended_at' => now()])->save();

            if ($session !== null && $session->isActive()) {
                $this->safeRevokeParticipant(
                    $session->room_name,
                    $this->livekit->callMemberIdentity($session->performer_profile_id, $member->id),
                );
            }
        }

        // Sessões 1:1 ativas onde ele é o membro: encerra + derruba a sala.
        $sessions = CallSession::where('member_id', $member->id)->active()->get();

        foreach ($sessions as $session) {
            $session->forceFill(['status' => 'ended', 'ended_at' => now()])->save();
            $this->safeDeleteRoom($session->room_name);
        }
    }

    /**
     * O membro já está numa chamada — 1:1 pending/active OU participação ativa em
     * group show? Fonte ÚNICA da exclusividade, lida pelo request/accept 1:1 e pelo
     * join do group. `static` porque não toca estado da instância.
     */
    public static function memberIsBusy(int $memberId): bool
    {
        return CallSession::where('member_id', $memberId)->occupying()->exists()
            || CallSessionParticipant::where('member_id', $memberId)->active()->exists();
    }

    // ── Configuração da performer ─────────────────────────────────────────────

    /**
     * Grava preço/minuto (piso 5, passo 5 — M.13.7) e teto de duração da performer.
     * `$pricePerMinute = null` = deixa de aceitar chamadas. Escrita por forceFill
     * (os campos ficam fora do $fillable). Valida o piso/passo como 2ª linha (o
     * Form Request é a 1ª) — a policy dos números é a config.
     */
    public function updateSettings(User $performerUser, ?int $pricePerMinute, ?int $maxDurationMinutes, ?int $slotMinutes = null): void
    {
        if ($pricePerMinute !== null) {
            $floor = (int) config('monetization.call_min_price_per_minute');
            $step = (int) config('monetization.call_price_step');
            if ($pricePerMinute < $floor || $step <= 0 || $pricePerMinute % $step !== 0) {
                throw CallException::invalidPrice();
            }
        }

        $performerUser->performerProfile->forceFill([
            'call_price_per_minute' => $pricePerMinute,
            'call_max_duration_minutes' => $maxDurationMinutes,
            // Duração-padrão do slot de AGENDAMENTO (feat/scheduled-call-v1). NULL =
            // usa o default de config/scheduled_call.php. A validação de faixa é do
            // Form Request (a 1ª linha); aqui a escrita segue a autoridade do service.
            'call_slot_minutes' => $slotMinutes,
        ])->save();
    }

    // ── GC ────────────────────────────────────────────────────────────────────

    /** Expira em lote os pending vencidos (command calls:expire-pending). Só faxina. */
    public function expireStalePending(): int
    {
        return CallSession::pending()
            ->where('created_at', '<', now()->subSeconds(CallSession::PENDING_TTL_SECONDS))
            ->update(['status' => 'expired']);
    }

    /**
     * Encerra chamadas 1:1 ABANDONADAS (o membro fechou a aba sem chamar `end`). A
     * cobrança é PULL: sem heartbeat/refresh a sessão fica `active` para sempre e
     * ambos os lados seguem `occupying()`, travados de iniciar nova chamada. Reaper:
     * `active` (só 1:1 — member_id não-nulo; group é do GroupShowService) cujo tempo
     * PAGO (`started_at + minutes_billed*60`) venceu há mais de `$graceSeconds` →
     * cliente sumiu → encerra + deleteRoom. Cliente saudável renova `minutes_billed`
     * e nunca é alcançado. Só faxina.
     */
    public function reapStaleSessions(int $graceSeconds = 120): int
    {
        $stale = CallSession::query()
            ->active()
            ->whereNotNull('member_id')
            ->whereRaw('DATE_ADD(started_at, INTERVAL (minutes_billed * 60 + ?) SECOND) < ?', [$graceSeconds, now()])
            ->get();

        foreach ($stale as $session) {
            $session->forceFill(['status' => 'ended', 'ended_at' => now()])->save();
            $this->safeDeleteRoom($session->room_name);
        }

        return $stale->count();
    }

    // ── Internos ──────────────────────────────────────────────────────────────

    /**
     * O ÚNICO caminho de cobrança (heartbeat E token-refresh). PRESSUPÕE que
     * `$lockedSession` já está travado (lockForUpdate) na transação do chamador.
     * Cobra os minutos devidos um a um enquanto cabe e sob o teto de tempo; muta
     * `minutes_billed` (e `status` para ended quando não sustenta) na MESMA linha
     * travada. Saldo < price → NÃO cobra (nunca negativo).
     *
     * @return array{balance:int, minutes_left:int, can_continue:bool, ended_reason:?string}
     */
    private function reconcileBilling(CallSession $lockedSession, User $member): array
    {
        $price = (int) $lockedSession->price_per_minute;
        $performer = $lockedSession->performerProfile?->user;

        // Performer sumiu mid-call (soft-delete/anonimização): não há para quem
        // creditar. Encerra limpo em vez de fatal no chargeMinute (🟢 da revisão).
        // Pré-checado aqui (não no MinuteBiller) porque só o 1:1 tem esse caso.
        if ($performer === null) {
            if ($lockedSession->isActive()) {
                $lockedSession->forceFill(['status' => 'ended', 'ended_at' => now()])->save();
            }
            $balance = $this->tokenService->balance($member);

            return [
                'balance' => $balance,
                'minutes_left' => $price > 0 ? (int) bcdiv($balance, (string) $price, 0) : 0,
                'can_continue' => false,
                'ended_reason' => 'ended',
            ];
        }

        // Motor de cobrança ÚNICO (compartilhado com o group show). Idempotência
        // por minuto, teto de duração e "saldo nunca negativo" vivem lá.
        $result = $this->minuteBiller->reconcile(
            $member,
            $price,
            (int) $lockedSession->minutes_billed,
            $lockedSession->started_at->getTimestamp(),
            $lockedSession->max_duration_minutes,
            fn () => $this->chargeMinute($lockedSession, $member, $performer),
        );

        $lockedSession->minutes_billed = $result['minutes_billed'];
        if (! $result['can_continue'] && $lockedSession->isActive()) {
            $lockedSession->forceFill(['status' => 'ended', 'ended_at' => now()])->save();
        } else {
            $lockedSession->save();
        }

        return [
            'balance' => $result['balance'],
            'minutes_left' => $result['minutes_left'],
            'can_continue' => $result['can_continue'],
            'ended_reason' => $result['ended_reason'],
        ];
    }

    /**
     * Cobra UM minuto: débito `spend_call` do membro + crédito `call_credit` da
     * performer (split 70/30, applied_rate=70 congelado — M.13.6/M.13.7). Ledger
     * append-only, débito sob lock do wallet (TokenService). Descrição por FanAlias,
     * nunca o id cru do membro.
     */
    private function chargeMinute(CallSession $session, User $member, User $performer): void
    {
        $price = (int) $session->price_per_minute;

        $this->tokenService->debit(
            $member,
            $price,
            'spend_call',
            CallSession::class,
            $session->id,
            'Chamada privada',
        );

        $this->creditPolicy->creditWithSplit(
            $performer,
            $price,
            'call',
            'call_credit',
            CallSession::class,
            $session->id,
            'Chamada privada de '.FanAlias::label($session->performer_profile_id, $member->id),
        );
    }

    /** Expira pendings vencidos DESTA performer, sob o lock do perfil (no request). */
    private function expireStalePendingForPerformer(int $profileId): void
    {
        CallSession::where('performer_profile_id', $profileId)
            ->pending()
            ->where('created_at', '<', now()->subSeconds(CallSession::PENDING_TTL_SECONDS))
            ->update(['status' => 'expired']);
    }

    /**
     * Avisa o fim. A performer recebe SEMPRE 'ended' neutro (M.13.10 — nunca sabe
     * se foi saldo/teto); o membro recebe o motivo real (ele já conhece o próprio
     * saldo).
     */
    private function dispatchEnded(CallSession $session, ?string $memberReason, bool $notifyMember, bool $notifyPerformer): void
    {
        if ($notifyMember) {
            CallEnded::dispatch($session->member_id, $session->id, $memberReason ?? 'ended');
        }
        if ($notifyPerformer) {
            CallEnded::dispatch($session->performerProfile->user_id, $session->id, 'ended');
        }
    }

    /** @return array{token:string, wsUrl:string} */
    private function tokenBundle(string $identity, string $role, string $room): array
    {
        return [
            // Chamada 1:1 é bidirecional: os dois publicam e assinam vídeo. Sem
            // canPublishData (nada de chat P2P não moderado — como a live).
            'token' => $this->livekit->generateToken($identity, $role, $room, [
                'canPublish' => true,
                'canSubscribe' => true,
                'canPublishData' => false,
            ]),
            'wsUrl' => (string) config('livekit.url'),
        ];
    }

    private function safeDeleteRoom(string $room): void
    {
        try {
            $this->livekit->deleteRoom($room);
        } catch (\Throwable) {
            // Rede/LiveKit fora não pode travar o encerramento: a call_session já
            // está ended (gate autoritativo: heartbeat/refresh dão 410) e os tokens
            // vivos expiram no token_ttl. Loga SEM o room_name (invariante).
            Log::warning('call.delete_room_failed');
        }
    }

    /**
     * Ejeta UM participante (revogação ativa) sem derrubar a sala — usado quando
     * só um membro sai (ban de membro em group). Best-effort: a linha já está
     * ended (gate autoritativo). Loga SEM room_name/identity (invariante).
     */
    private function safeRevokeParticipant(string $room, string $identity): void
    {
        try {
            $this->livekit->revokeParticipant($room, $identity);
        } catch (\Throwable) {
            Log::warning('call.revoke_participant_failed');
        }
    }
}
