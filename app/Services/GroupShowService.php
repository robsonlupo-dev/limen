<?php

namespace App\Services;

use App\Events\GroupEnded;
use App\Events\GroupParticipantLeft;
use App\Events\GroupUpgradeRequested;
use App\Events\GroupUpgradeResolved;
use App\Exceptions\CallException;
use App\Exceptions\InsufficientBalanceException;
use App\Jobs\RevokeGroupParticipants;
use App\Models\CallSession;
use App\Models\CallSessionParticipant;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Support\FanAlias;
use App\Support\MinuteBiller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dona ÚNICA do ciclo de vida E da cobrança do group show 1:X (Sprint 15, PR #141).
 * A performer transmite para N membros pagantes; cada membro tem a PRÓPRIA cobrança
 * (linha em `call_session_participants`, `minutes_billed` próprio). Reusa o
 * `MinuteBiller` (o MESMO motor pré-pago do 1:1), TokenService (débito),
 * TokenCreditPolicy (split 70/30 congelado, `applied_rate=70`), LiveKitService (sala
 * + JWT), e os `entry_type` `spend_call`/`call_credit` — sem tipos novos.
 *
 * ── Anonimato (FanAlias / M.13.10) ───────────────────────────────────────────
 * A performer nunca vê member_id, tier nem saldo. Identity da sala = FanAlias handle
 * por par; broadcasts levam o LABEL (4 díg.). "Saiu" é sempre neutro — a performer
 * não distingue saída voluntária de falta de saldo ou ban.
 *
 * ── Upgrade para 1:1 (mesma sala) ────────────────────────────────────────────
 * Um membro pede 1:1; se a performer aceitar, o solicitante SOBREVIVE (preço passa
 * ao 1:1), a sessão vira `type=private` (bloqueia novos joins) e os OUTROS são
 * encerrados (cobrança para já) + ejetados por um job com atraso de 10s
 * (RevokeGroupParticipants — teardown autoritativo, mesmo que o cliente ignore a
 * despedida). O billing do sobrevivente segue por este service, agora ao preço 1:1.
 */
class GroupShowService
{
    public function __construct(
        private TokenService $tokenService,
        private TokenCreditPolicy $creditPolicy,
        private LiveKitService $livekit,
        private MinuteBiller $minuteBiller,
    ) {}

    // ── Performer: start / stop ───────────────────────────────────────────────

    /**
     * A performer inicia o group show. Idempotente sob o lock do perfil (2º start
     * devolve a mesma sessão, sem 2ª sala). Cria a sala com `max_participants + 1`
     * (a performer publica). Snapshota o preço 1:1 (`upgrade_price_per_minute`) —
     * NULL se ela não configurou 1:1 (upgrade indisponível).
     *
     * @return array{token:string, wsUrl:string}
     */
    public function start(User $performerUser, int $pricePerMinute, int $maxParticipants): array
    {
        $this->assertValidPrice($pricePerMinute);
        $cap = (int) config('livekit.max_participants_group');
        if ($maxParticipants < 2 || $maxParticipants > $cap) {
            throw CallException::invalidPrice();
        }

        $profile = $performerUser->performerProfile;

        $session = DB::transaction(function () use ($profile, $pricePerMinute, $maxParticipants) {
            $lockedProfile = PerformerProfile::whereKey($profile->id)->lockForUpdate()->firstOrFail();

            // Já transmitindo → devolve a mesma sessão (idempotente, sem 2ª sala).
            $existing = CallSession::where('performer_profile_id', $lockedProfile->id)
                ->active()
                ->where('type', CallSession::TYPE_GROUP)
                ->first();
            if ($existing) {
                return $existing;
            }

            // Nem 1:1 nem group em curso ocupando a performer.
            if (CallSession::where('performer_profile_id', $lockedProfile->id)->occupying()->exists()) {
                throw CallException::conflict();
            }

            $room = $this->livekit->groupRoomName($lockedProfile->id);
            $this->livekit->createRoom($room, $maxParticipants + 1);

            $session = new CallSession([
                'performer_profile_id' => $lockedProfile->id,
                'room_name' => $room,
                'price_per_minute' => $pricePerMinute,
                'max_duration_minutes' => null,
            ]);
            $session->forceFill([
                'type' => CallSession::TYPE_GROUP,
                'status' => 'active',
                'member_id' => null,
                'max_participants' => $maxParticipants,
                'upgrade_price_per_minute' => $lockedProfile->call_price_per_minute,
                'started_at' => now(),
                'minutes_billed' => 0,
            ])->save();

            return $session;
        });

        return $this->tokenBundle(
            $this->livekit->performerIdentity($session->performer_profile_id),
            LiveKitService::ROLE_PERFORMER,
            $session->room_name,
            ['canPublish' => true, 'canSubscribe' => false, 'canPublishData' => false],
        );
    }

    /** Encerra o group show ativo da performer + todos os participantes + a sala. */
    public function stop(User $performerUser): void
    {
        $result = DB::transaction(function () use ($performerUser) {
            $profile = $performerUser->performerProfile;
            if ($profile === null) {
                return ['session' => null, 'members' => []];
            }

            $locked = CallSession::where('performer_profile_id', $profile->id)
                ->active()
                ->where('type', CallSession::TYPE_GROUP)
                ->lockForUpdate()
                ->first();
            if ($locked === null) {
                return ['session' => null, 'members' => []];
            }

            $members = CallSessionParticipant::where('call_session_id', $locked->id)
                ->active()->pluck('member_id')->all();

            CallSessionParticipant::where('call_session_id', $locked->id)
                ->active()->update(['status' => 'ended', 'ended_at' => now()]);

            $locked->forceFill(['status' => 'ended', 'ended_at' => now()])->save();

            return ['session' => $locked, 'members' => $members];
        });

        if ($result['session'] !== null) {
            $this->safeDeleteRoom($result['session']->room_name);
            foreach ($result['members'] as $memberId) {
                GroupEnded::dispatch($memberId, $result['session']->id, 'performer_stopped');
            }
        }
    }

    // ── Membro: join / heartbeat / refresh / leave ────────────────────────────

    /**
     * O membro entra no group show. Valida saldo do 1º minuto, sala group ativa,
     * vagas (sob lock da sessão) e exclusividade do membro (sob lock-âncora do
     * users). Cria/reativa a participação, cobra o 1º minuto, devolve o JWT viewer
     * (subscribe, sem publish/data). Identity = FanAlias handle por par.
     *
     * @return array{token:string, wsUrl:string}
     */
    public function join(User $member, CallSession $group): array
    {
        if (! $group->isGroup() || ! $group->isActive()) {
            throw CallException::gone();
        }
        $performerUser = $group->performerProfile?->user;
        if ($performerUser === null || $performerUser->status !== 'active') {
            throw CallException::gone();
        }
        if ($member->id === $performerUser->id) {
            throw CallException::notFound();
        }

        $price = (int) $group->price_per_minute;
        if ($this->tokenService->balance($member) < $price) {
            throw CallException::insufficientBalance();
        }

        DB::transaction(function () use ($member, $group, $price, $performerUser) {
            // Lock da sessão: serializa joins concorrentes (o teto de vagas é duro
            // para um mesmo group).
            $locked = CallSession::whereKey($group->id)->lockForUpdate()->first();
            if ($locked === null || ! $locked->isGroup() || ! $locked->isActive()) {
                throw CallException::gone();
            }

            // Exclusividade do membro (âncora no users): nem 1:1 nem outra
            // participação group ativa. Um re-join a ESTE group quando ainda ativo
            // cai aqui (memberIsBusy true).
            User::whereKey($member->id)->lockForUpdate()->first();
            if (CallService::memberIsBusy($member->id)) {
                throw CallException::conflict();
            }

            // Vagas: participantes ATIVOS < teto de membros.
            $activeCount = CallSessionParticipant::where('call_session_id', $locked->id)->active()->count();
            if ($activeCount >= (int) $locked->max_participants) {
                throw CallException::full();
            }

            // Cria (ou reativa uma participação encerrada) e cobra o 1º minuto. O
            // save antes do charge dá o id para a referência do ledger.
            $participant = CallSessionParticipant::where('call_session_id', $locked->id)
                ->where('member_id', $member->id)->first()
                ?? new CallSessionParticipant([
                    'call_session_id' => $locked->id,
                    'member_id' => $member->id,
                    'price_per_minute' => $price,
                ]);
            $participant->price_per_minute = $price;
            $participant->forceFill([
                'status' => 'active',
                'minutes_billed' => 0,
                'joined_at' => now(),
                'ended_at' => null,
            ])->save();

            try {
                $this->chargeMinute($locked, $participant, $member, $performerUser);
            } catch (InsufficientBalanceException) {
                throw CallException::insufficientBalance();
            }

            $participant->forceFill(['minutes_billed' => 1])->save();
        });

        return $this->tokenBundle(
            $this->livekit->callMemberIdentity($group->performer_profile_id, $member->id),
            LiveKitService::ROLE_MEMBER,
            $group->room_name,
            ['canPublish' => false, 'canSubscribe' => true, 'canPublishData' => false],
        );
    }

    /**
     * Batimento do FRONT do membro: cobra o próprio minuto (independente dos outros).
     * Sem saldo → encerra SÓ este participante (os demais ficam). Idempotente por
     * minuto por participante. Não é participante desta sessão / de terceiro → 404;
     * participação/sessão encerrada → 410.
     *
     * @return array{minutes_elapsed:int, balance_remaining:int, minutes_left:int, can_continue:bool}
     */
    public function heartbeat(User $member, CallSession $group): array
    {
        $outcome = DB::transaction(function () use ($member, $group) {
            $participant = $this->lockParticipant($group->id, $member->id);
            if ($participant === null) {
                throw CallException::notFound();
            }
            if (! $participant->isActive()) {
                throw CallException::gone();
            }

            $session = CallSession::find($group->id);
            if ($session === null || ! $session->isActive()) {
                // Grupo encerrado (performer/ban): este participante está fora.
                $participant->forceFill(['status' => 'ended', 'ended_at' => now()])->save();
                throw CallException::gone();
            }

            $billing = $this->reconcileParticipant($participant, $session, $member);

            return ['participant' => $participant, 'session' => $session, 'billing' => $billing];
        });

        if ($outcome['billing']['can_continue'] === false) {
            // A performer vê "{FanAlias} saiu" (neutro); o membro já sabe do saldo.
            $this->dispatchParticipantLeft($outcome['session'], $member);
        }

        return [
            'minutes_elapsed' => $outcome['participant']->minutes_billed,
            'balance_remaining' => $outcome['billing']['balance'],
            'minutes_left' => $outcome['billing']['minutes_left'],
            'can_continue' => $outcome['billing']['can_continue'],
        ];
    }

    /**
     * Renova o JWT do membro (a cada ~4min). Reconcilia a cobrança (fecha a carona
     * como no 1:1) e re-verifica que os dois lados estão de pé. Sem saldo / grupo
     * encerrado / performer banida → revoga SÓ este participante + 410.
     *
     * @return array{token:string, wsUrl:string}
     */
    public function refreshToken(User $member, CallSession $group): array
    {
        $state = DB::transaction(function () use ($member, $group) {
            $participant = $this->lockParticipant($group->id, $member->id);
            if ($participant === null) {
                throw CallException::notFound();
            }
            if (! $participant->isActive()) {
                throw CallException::gone();
            }

            $session = CallSession::find($group->id);
            $performerUser = $session?->performerProfile?->user;
            if ($session === null || ! $session->isActive()
                || $performerUser === null || $performerUser->status !== 'active'
                || $member->status !== 'active') {
                $participant->forceFill(['status' => 'ended', 'ended_at' => now()])->save();

                return ['ended' => true, 'session' => $session, 'reason' => 'performer_stopped'];
            }

            $billing = $this->reconcileParticipant($participant, $session, $member);
            if ($billing['can_continue'] === false) {
                return ['ended' => true, 'session' => $session, 'reason' => $billing['ended_reason']];
            }

            return ['ended' => false, 'session' => $session];
        });

        if ($state['ended']) {
            if ($state['session'] !== null) {
                $this->safeRevokeParticipant(
                    $state['session']->room_name,
                    $this->livekit->callMemberIdentity($state['session']->performer_profile_id, $member->id),
                );
                $this->dispatchParticipantLeft($state['session'], $member);
            }
            throw CallException::gone();
        }

        return $this->tokenBundle(
            $this->livekit->callMemberIdentity($group->performer_profile_id, $member->id),
            LiveKitService::ROLE_MEMBER,
            $group->room_name,
            ['canPublish' => false, 'canSubscribe' => true, 'canPublishData' => false],
        );
    }

    /** Saída voluntária do membro. Encerra o participante + revoga. Idempotente. */
    public function leave(User $member, CallSession $group): void
    {
        $state = DB::transaction(function () use ($member, $group) {
            $participant = $this->lockParticipant($group->id, $member->id);
            if ($participant === null) {
                throw CallException::notFound();
            }
            if (! $participant->isActive()) {
                return ['already' => true];
            }

            $participant->forceFill(['status' => 'ended', 'ended_at' => now()])->save();

            return ['already' => false, 'session' => CallSession::find($group->id)];
        });

        if ($state['already'] ?? false) {
            return;
        }

        if ($state['session'] !== null && $state['session']->isActive()) {
            $this->safeRevokeParticipant(
                $state['session']->room_name,
                $this->livekit->callMemberIdentity($state['session']->performer_profile_id, $member->id),
            );
            $this->dispatchParticipantLeft($state['session'], $member);
        }
    }

    // ── Upgrade para 1:1 ──────────────────────────────────────────────────────

    /** O membro pede upgrade para 1:1. Um pedido pendente por vez (2º → 409). */
    public function upgradeRequest(User $member, CallSession $group): void
    {
        $result = DB::transaction(function () use ($member, $group) {
            $locked = CallSession::whereKey($group->id)->lockForUpdate()->first();
            if ($locked === null || ! $locked->isGroup() || ! $locked->isActive()) {
                throw CallException::gone();
            }

            $isParticipant = CallSessionParticipant::where('call_session_id', $locked->id)
                ->where('member_id', $member->id)->active()->exists();
            if (! $isParticipant) {
                throw CallException::notFound();
            }
            if ($locked->upgrade_price_per_minute === null) {
                throw CallException::unavailable(); // performer sem preço 1:1
            }
            // Já há pedido pendente de OUTRO membro → recusa (o solicitante pode
            // repetir o próprio, só renova o carimbo).
            if ($locked->hasPendingUpgrade() && $locked->upgrade_requested_by !== $member->id) {
                throw CallException::upgradePending();
            }

            $locked->forceFill([
                'upgrade_requested_by' => $member->id,
                'upgrade_requested_at' => now(),
            ])->save();

            return ['performerUserId' => $locked->performerProfile?->user_id];
        });

        if ($result['performerUserId'] !== null) {
            GroupUpgradeRequested::dispatch(
                $result['performerUserId'],
                $group->id,
                FanAlias::label($group->performer_profile_id, $member->id),
            );
        }
    }

    /**
     * A performer aceita o upgrade. Sob o lock da sessão: reconcilia o solicitante
     * ao preço-GRUPO (A3), troca o preço dele para 1:1, encerra os OUTROS (cobrança
     * para já) e vira a sessão `private` — tudo atômico. Broadcasts + revoke com
     * atraso de 10s acontecem PÓS-COMMIT.
     */
    public function upgradeAccept(User $performerUser, CallSession $group): void
    {
        $result = DB::transaction(function () use ($performerUser, $group) {
            $profile = $performerUser->performerProfile;
            $locked = CallSession::whereKey($group->id)->lockForUpdate()->first();
            if ($locked === null || $profile === null || $locked->performer_profile_id !== $profile->id) {
                throw CallException::notFound();
            }
            if (! $locked->isGroup() || ! $locked->isActive()) {
                throw CallException::gone();
            }
            if (! $locked->hasPendingUpgrade() || $locked->upgrade_price_per_minute === null) {
                throw CallException::notFound(); // pedido vencido/inexistente ou sem preço 1:1
            }

            $requesterId = (int) $locked->upgrade_requested_by;
            $survivor = CallSessionParticipant::where('call_session_id', $locked->id)
                ->where('member_id', $requesterId)->active()->lockForUpdate()->first();
            if ($survivor === null) {
                // Solicitante saiu antes do aceite → aborta. Nada foi escrito ainda;
                // o pedido vence sozinho em 60s (hasPendingUpgrade). Sinaliza gone
                // PÓS-COMMIT (não joga aqui — manteria simetria e não perderia writes).
                return ['aborted' => true];
            }

            // A3: cobra o solicitante ao preço-GRUPO até agora ANTES de trocar o
            // preço, para o minuto de transição não sair ao preço errado.
            $billing = $this->reconcileParticipant($survivor, $locked, $survivor->member);
            if ($billing['can_continue'] === false || ! $survivor->isActive()) {
                // Sem saldo no minuto de transição → aborta o upgrade, mas COMMITA a
                // cobrança já feita (retorna em vez de jogar — jogar reverteria os
                // minutos que couberam). O grupo segue; o gone sai pós-commit.
                return ['aborted' => true];
            }

            // Solicitante sobrevive ao preço 1:1.
            $survivor->forceFill(['price_per_minute' => (int) $locked->upgrade_price_per_minute])->save();

            // Os OUTROS encerram (cobrança para já). lockForUpdate serializa com os
            // heartbeats deles.
            $others = CallSessionParticipant::where('call_session_id', $locked->id)
                ->active()->whereKeyNot($survivor->id)->lockForUpdate()->get();

            $otherMemberIds = [];
            $otherIdentities = [];
            foreach ($others as $other) {
                $other->forceFill(['status' => 'ended', 'ended_at' => now()])->save();
                $otherMemberIds[] = $other->member_id;
                $otherIdentities[] = $this->livekit->callMemberIdentity($locked->performer_profile_id, $other->member_id);
            }

            // Sessão vira private (bloqueia novos joins), limpa o pedido.
            $locked->forceFill([
                'type' => CallSession::TYPE_PRIVATE,
                'upgrade_requested_by' => null,
                'upgrade_requested_at' => null,
            ])->save();

            return [
                'aborted' => false,
                'session' => $locked,
                'survivorMemberId' => $requesterId,
                'otherMemberIds' => $otherMemberIds,
                'otherIdentities' => $otherIdentities,
            ];
        });

        if ($result['aborted']) {
            throw CallException::gone();
        }

        // Pós-commit: avisa o solicitante, avisa os removidos e agenda o revoke de
        // 10s (teardown autoritativo — o front deles mostra a despedida na janela).
        GroupUpgradeResolved::dispatch($result['survivorMemberId'], $group->id, true);
        foreach ($result['otherMemberIds'] as $memberId) {
            GroupEnded::dispatch($memberId, $group->id, 'upgraded');
        }
        if (! empty($result['otherIdentities'])) {
            RevokeGroupParticipants::dispatch($result['session']->room_name, $result['otherIdentities'])
                ->delay(now()->addSeconds(10));
        }
    }

    /** A performer recusa o upgrade. Limpa o pedido, avisa o solicitante. Grupo segue. */
    public function upgradeDecline(User $performerUser, CallSession $group): void
    {
        $result = DB::transaction(function () use ($performerUser, $group) {
            $profile = $performerUser->performerProfile;
            $locked = CallSession::whereKey($group->id)->lockForUpdate()->first();
            if ($locked === null || $profile === null || $locked->performer_profile_id !== $profile->id) {
                throw CallException::notFound();
            }
            if (! $locked->isGroup() || ! $locked->isActive()) {
                throw CallException::gone();
            }

            $requesterId = $locked->hasPendingUpgrade() ? (int) $locked->upgrade_requested_by : null;
            $locked->forceFill(['upgrade_requested_by' => null, 'upgrade_requested_at' => null])->save();

            return ['requesterId' => $requesterId];
        });

        if ($result['requesterId'] !== null) {
            GroupUpgradeResolved::dispatch($result['requesterId'], $group->id, false);
        }
    }

    // ── GC ────────────────────────────────────────────────────────────────────

    /**
     * Encerra participações ABANDONADAS (o membro fechou a aba sem chamar `leave`).
     * A cobrança é PULL: uma participação sem heartbeat fica `active` para sempre e
     * `CallService::memberIsBusy()` a lê como "ocupado", travando o membro de entrar
     * em qualquer call/group (achado 🟡 da revisão de código). Reaper: `active`
     * cujo tempo PAGO (`joined_at + minutes_billed*60`) venceu há mais de
     * `$graceSeconds` sem novo minuto → cliente sumiu → encerra. Um cliente saudável
     * renova `minutes_billed` a cada minuto e nunca é alcançado. Só faxina — não
     * cobra nada; o LiveKit já ejetou o cliente sumido (token expira em ≤ token_ttl).
     */
    public function expireStaleParticipants(int $graceSeconds = 120): int
    {
        return CallSessionParticipant::query()
            ->active()
            ->whereRaw('DATE_ADD(joined_at, INTERVAL (minutes_billed * 60 + ?) SECOND) < ?', [$graceSeconds, now()])
            ->update(['status' => 'ended', 'ended_at' => now()]);
    }

    // ── Internos ──────────────────────────────────────────────────────────────

    private function lockParticipant(int $sessionId, int $memberId): ?CallSessionParticipant
    {
        return CallSessionParticipant::where('call_session_id', $sessionId)
            ->where('member_id', $memberId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Reconcilia a cobrança de UM participante pelo MinuteBiller (o mesmo motor do
     * 1:1). PRESSUPÕE a linha travada. Group não tem teto de duração por participante
     * (maxMinutes=null). Performer sumiu → encerra limpo.
     *
     * @return array{balance:int, minutes_left:int, can_continue:bool, ended_reason:?string}
     */
    private function reconcileParticipant(CallSessionParticipant $lockedParticipant, CallSession $session, User $member): array
    {
        $price = (int) $lockedParticipant->price_per_minute;
        $performer = $session->performerProfile?->user;

        if ($performer === null) {
            if ($lockedParticipant->isActive()) {
                $lockedParticipant->forceFill(['status' => 'ended', 'ended_at' => now()])->save();
            }
            $balance = $this->tokenService->balance($member);

            return [
                'balance' => $balance,
                'minutes_left' => $price > 0 ? intdiv($balance, $price) : 0,
                'can_continue' => false,
                'ended_reason' => 'ended',
            ];
        }

        $result = $this->minuteBiller->reconcile(
            $member,
            $price,
            (int) $lockedParticipant->minutes_billed,
            $lockedParticipant->joined_at->getTimestamp(),
            null,
            fn () => $this->chargeMinute($session, $lockedParticipant, $member, $performer),
        );

        $lockedParticipant->minutes_billed = $result['minutes_billed'];
        if (! $result['can_continue'] && $lockedParticipant->isActive()) {
            $lockedParticipant->forceFill(['status' => 'ended', 'ended_at' => now()])->save();
        } else {
            $lockedParticipant->save();
        }

        return [
            'balance' => $result['balance'],
            'minutes_left' => $result['minutes_left'],
            'can_continue' => $result['can_continue'],
            'ended_reason' => $result['ended_reason'],
        ];
    }

    /**
     * Cobra UM minuto do participante: débito `spend_call` + crédito `call_credit`
     * (split 70/30, applied_rate=70 congelado). Referência = a PARTICIPAÇÃO (audita
     * quem pagou, além da descrição por FanAlias). Ledger append-only, débito sob
     * lock do wallet.
     */
    private function chargeMinute(CallSession $session, CallSessionParticipant $participant, User $member, User $performer): void
    {
        $price = (int) $participant->price_per_minute;

        $this->tokenService->debit(
            $member,
            $price,
            'spend_call',
            CallSessionParticipant::class,
            $participant->id,
            'Group show',
        );

        $this->creditPolicy->creditWithSplit(
            $performer,
            $price,
            'call',
            'call_credit',
            CallSessionParticipant::class,
            $participant->id,
            'Group show de '.FanAlias::label($session->performer_profile_id, $member->id),
        );
    }

    private function dispatchParticipantLeft(CallSession $session, User $member): void
    {
        $performerUserId = $session->performerProfile?->user_id;
        if ($performerUserId === null) {
            return; // perfil sumiu mid-group (janela estreita) — nada a avisar.
        }

        GroupParticipantLeft::dispatch(
            $performerUserId,
            $session->id,
            FanAlias::label($session->performer_profile_id, $member->id),
        );
    }

    private function assertValidPrice(int $pricePerMinute): void
    {
        $floor = (int) config('monetization.call_min_price_per_minute');
        $step = (int) config('monetization.call_price_step');
        if ($pricePerMinute < $floor || $step <= 0 || $pricePerMinute % $step !== 0) {
            throw CallException::invalidPrice();
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

    private function safeDeleteRoom(string $room): void
    {
        try {
            $this->livekit->deleteRoom($room);
        } catch (\Throwable) {
            Log::warning('group.delete_room_failed');
        }
    }

    private function safeRevokeParticipant(string $room, string $identity): void
    {
        try {
            $this->livekit->revokeParticipant($room, $identity);
        } catch (\Throwable) {
            Log::warning('group.revoke_participant_failed');
        }
    }
}
