<?php

namespace App\Services;

use App\Exceptions\FanclubException;
use App\Models\FanclubMembership;
use App\Models\FanclubSettings;
use App\Models\PerformerProfile;
use App\Models\Subscription;
use App\Models\TokenLedger;
use App\Models\TokenWallet;
use App\Models\User;
use App\Support\Audit;
use App\Support\FanAlias;
use App\Support\TokenMath;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fã-Clube da performer (Onda 4 — fork da assinatura, docs/FORK_ASSINATURA.md). Dona
 * ÚNICA do ciclo e da contabilidade da assinatura por-performer. Trilho SÓ token,
 * split 80/20 (rate 'content', como conteúdo permanente): débito `spend_fanclub_sub`
 * do membro, crédito `fanclub_sub_credit` à performer (sacável, fora do teto).
 *
 * Disciplina de dinheiro (espelha ContentUnlockService/CustomOrderService): as duas
 * carteiras são travadas em ordem crescente de user_id numa transação; a linha da
 * assinatura é relida sob lock; idempotência por UNIQUE(member, performer).
 *
 * Anonimato (M.13.10): a performer vê o assinante SEMPRE por FanAlias — o `member_id` é
 * chave interna. Nenhuma descrição de ledger da performer leva id/nome do membro.
 */
class FanclubService
{
    public function __construct(
        private TokenService $tokenService,
        private TokenCreditPolicy $creditPolicy,
    ) {}

    // ── Config da performer ───────────────────────────────────────────────────

    /**
     * A performer abre/edita o fã-clube dela: liga/desliga e define preços (token). Valida
     * piso/passo/teto e a trava público ≥ VIP. Dona única da escrita (forceFill).
     */
    public function saveSettings(
        PerformerProfile $profile,
        bool $isOpen,
        ?int $pricePublic,
        bool $vipEnabled = false,
        ?int $priceVip = null,
    ): FanclubSettings {
        if ($isOpen) {
            if ($pricePublic === null || ! $this->creditPolicy->isValidFanclubPrice($pricePublic)) {
                throw FanclubException::invalid('Defina um preço público válido para abrir o fã-clube.');
            }
            if ($vipEnabled) {
                if ($priceVip === null || ! $this->creditPolicy->isValidFanclubPrice($priceVip)) {
                    throw FanclubException::invalid('Defina um preço VIP válido.');
                }
                // Trava de negócio: VIP nunca mais caro que o público (público ≥ VIP).
                if ($priceVip > $pricePublic) {
                    throw FanclubException::invalid('O preço VIP não pode ser maior que o público.');
                }
            }
        }

        // where+new (não firstOrNew): a model tem $fillable vazio, então mass-assign do
        // performer_profile_id no firstOrNew dispara MassAssignmentException. forceFill bypassa.
        $settings = FanclubSettings::where('performer_profile_id', $profile->id)->first() ?? new FanclubSettings;
        $settings->forceFill([
            'performer_profile_id' => $profile->id,
            'is_open' => $isOpen,
            'price_public_tokens' => $isOpen ? $pricePublic : $settings->price_public_tokens,
            'vip_enabled' => $isOpen ? $vipEnabled : false,
            'price_vip_tokens' => ($isOpen && $vipEnabled) ? $priceVip : null,
        ])->save();

        Audit::log('fanclub.settings_saved', $profile, [
            'is_open' => $isOpen,
            'vip_enabled' => $isOpen && $vipEnabled,
        ]);

        return $settings->refresh();
    }

    // ── Assinatura (membro) ───────────────────────────────────────────────────

    /**
     * O membro assina (ou REATIVA) o fã-clube da performer. Cobra o preço na hora do saldo
     * de token; cria/reativa a linha com o ciclo começando agora. Idempotente: assinatura
     * já ativa e vigente é devolvida sem recobrar.
     */
    public function subscribe(User $member, PerformerProfile $profile): FanclubMembership
    {
        $performerUser = $profile->user;

        // Performer fora do ar / inexistente → indisponível (antes do self, que precisa dela).
        $settings = FanclubSettings::where('performer_profile_id', $profile->id)->first();
        if ($performerUser === null || ! $settings || ! $settings->isSubscribable() || ! $this->performerIsReachable($profile)) {
            throw FanclubException::notOpen();
        }

        // Self barrado antes de qualquer lock/débito (a dona não assina o próprio clube).
        if ($performerUser->id === $member->id) {
            throw FanclubException::self();
        }

        // Só consumidor assina: o serving (canView) só atende role=consumer, então cobrar
        // qualquer outro papel criaria uma assinatura paga que NUNCA veria o conteúdo
        // (espelha a guarda de denialForUnlock). O controller futuro também valida; o
        // service de dinheiro não confia nisso.
        if ($member->role !== 'consumer') {
            throw FanclubException::invalid('Apenas membros podem assinar um fã-clube.');
        }

        try {
            return $this->doSubscribe($member, $profile, $performerUser, $settings);
        } catch (UniqueConstraintViolationException) {
            // Corrida perdida apesar do lock de carteira: a transação reverteu (sem cobrança
            // dupla). Devolve a linha vencedora — o resultado que o membro queria.
            return FanclubMembership::where('member_id', $member->id)
                ->where('performer_profile_id', $profile->id)
                ->firstOrFail();
        }
    }

    private function doSubscribe(
        User $member,
        PerformerProfile $profile,
        User $performerUser,
        FanclubSettings $settings,
    ): FanclubMembership {
        [$price, $tier] = $settings->priceFor($member);

        return DB::transaction(function () use ($member, $profile, $performerUser, $price, $tier) {
            TokenWallet::firstOrCreate(['user_id' => $member->id], ['balance' => 0]);
            TokenWallet::firstOrCreate(['user_id' => $performerUser->id], ['balance' => 0]);

            // Locks em ordem crescente de user_id (anti-deadlock).
            TokenWallet::whereIn('user_id', [$member->id, $performerUser->id])
                ->orderBy('user_id')
                ->lockForUpdate()
                ->get();

            // Relê a assinatura sob o lock da carteira do membro (serializa mesmo-membro).
            $membership = FanclubMembership::where('member_id', $member->id)
                ->where('performer_profile_id', $profile->id)
                ->lockForUpdate()
                ->first();

            // Já ativa e vigente → idempotente, não recobra.
            if ($membership && $membership->isActive() && $membership->current_period_end?->isFuture()) {
                return $membership;
            }

            // Revalida a performer DENTRO da transação (suspensão/ban não serializa no lock
            // de carteira; a pré-checagem fora da transação pode estar obsoleta). Fecha a
            // janela de cobrar uma assinatura para performer que acabou de sair do ar.
            if (! $this->performerIsReachable($profile)) {
                throw FanclubException::notOpen();
            }

            // Teto de assinaturas ativas por membro (conta OUTRAS performers; a deste par,
            // se ativa, já teria retornado acima).
            $activeOthers = FanclubMembership::where('member_id', $member->id)
                ->where('performer_profile_id', '!=', $profile->id)
                ->where('status', FanclubMembership::STATUS_ACTIVE)
                ->count();
            if ($activeOthers >= (int) config('fanclub.max_active_per_member')) {
                throw FanclubException::limit();
            }

            $spend = $this->charge($member, $performerUser, $profile, $price);

            $now = now();
            $end = (clone $now)->addDays((int) config('fanclub.cycle_days'));

            if (! $membership) {
                $membership = new FanclubMembership;
                $membership->member_id = $member->id;
                $membership->performer_profile_id = $profile->id;
            }

            $membership->forceFill([
                'status' => FanclubMembership::STATUS_ACTIVE,
                'price_tokens' => $price,
                'price_tier' => $tier,
                'current_period_start' => $now,
                'current_period_end' => $end,
                'grace_until' => null,
                'cancel_requested' => false,
                'paused_at' => null,
                'cancelled_at' => null,
                'last_charge_ledger_id' => $spend->id,
            ])->save();

            Audit::log('fanclub.subscribed', $profile, ['price_tier' => $tier]);

            return $membership;
        });
    }

    /**
     * "Desvincular": o membro pede o cancelamento. Mantém o ACESSO até o fim do ciclo já
     * pago; o cron encerra (cancelled) na virada. Idempotente; no-op se não houver
     * assinatura ativa.
     */
    public function cancel(User $member, PerformerProfile $profile): ?FanclubMembership
    {
        $membership = FanclubMembership::where('member_id', $member->id)
            ->where('performer_profile_id', $profile->id)
            ->where('status', FanclubMembership::STATUS_ACTIVE)
            ->first();

        if (! $membership) {
            return null;
        }

        $membership->forceFill(['cancel_requested' => true])->save();
        Audit::log('fanclub.cancel_requested', $profile, []);

        return $membership;
    }

    // ── Renovação (cron) ──────────────────────────────────────────────────────

    /**
     * Motor de tempo (fanclub:process). Para cada assinatura ativa vencida: encerra as
     * canceladas, renova cobrando o saldo, entra em carência quando falta token e pausa
     * quando a carência vence. Idempotente (a renovação empurra o período; reprocessar não
     * recobra). Cada linha em sua própria transação — uma falha não derruba o lote.
     *
     * @return array{renewed:int, graced:int, paused:int, cancelled:int, skipped:int}
     */
    public function processRenewals(): array
    {
        $counts = ['renewed' => 0, 'graced' => 0, 'paused' => 0, 'cancelled' => 0, 'skipped' => 0];

        FanclubMembership::query()
            ->where('status', FanclubMembership::STATUS_ACTIVE)
            ->where('current_period_end', '<=', now())
            ->orderBy('id')
            ->chunkById(200, function ($due) use (&$counts) {
                foreach ($due as $m) {
                    try {
                        $outcome = $this->renewOne($m->id);
                        $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
                    } catch (\Throwable $e) {
                        Log::error('fanclub:renew failed', ['membership_id' => $m->id, 'error' => $e->getMessage()]);
                        $counts['skipped']++;
                    }
                }
            });

        return $counts;
    }

    private function renewOne(int $membershipId): string
    {
        // Leitura SEM lock só para descobrir os participantes e travar as carteiras ANTES
        // da linha da assinatura — a MESMA ordem (carteiras → assinatura) do subscribe, pra
        // não haver deadlock ABBA entre o cron e um subscribe concorrente no mesmo par.
        $pre = FanclubMembership::find($membershipId);
        if (! $pre || ! $pre->isActive() || $pre->current_period_end?->isFuture()) {
            return 'skipped';
        }
        $profile = $pre->performerProfile;
        $performerUser = $profile?->user;
        $member = $pre->member;

        return DB::transaction(function () use ($membershipId, $profile, $performerUser, $member) {
            // Trava as carteiras PRIMEIRO (ordem crescente de user_id), quando ambos existem.
            // Se faltar membro/performer (soft-delete), não há cobrança — segue direto para o
            // encerramento, sem lock de carteira.
            if ($member !== null && $performerUser !== null) {
                TokenWallet::firstOrCreate(['user_id' => $member->id], ['balance' => 0]);
                TokenWallet::firstOrCreate(['user_id' => $performerUser->id], ['balance' => 0]);
                TokenWallet::whereIn('user_id', [$member->id, $performerUser->id])
                    ->orderBy('user_id')
                    ->lockForUpdate()
                    ->get();
            }

            // Agora trava e relê a linha da assinatura (barreira de idempotência intacta).
            $m = FanclubMembership::where('id', $membershipId)->lockForUpdate()->first();
            if (! $m || ! $m->isActive() || $m->current_period_end?->isFuture()) {
                return 'skipped';
            }

            // Desvinculada → encerra no fim do ciclo pago.
            if ($m->cancel_requested) {
                $m->forceFill(['status' => FanclubMembership::STATUS_CANCELLED, 'cancelled_at' => now()])->save();

                return 'cancelled';
            }

            // Clube fechado/sem preço, performer fora do ar, ou membro inválido (soft-delete
            // ou não-consumer) → encerra (não há como renovar nem servir).
            $settings = $profile ? FanclubSettings::where('performer_profile_id', $profile->id)->first() : null;
            if (! $settings || ! $settings->isSubscribable() || $performerUser === null
                || $member === null || $member->role !== 'consumer' || ! $this->performerIsReachable($profile)) {
                $m->forceFill(['status' => FanclubMembership::STATUS_CANCELLED, 'cancelled_at' => now()])->save();

                return 'cancelled';
            }

            [$price, $tier] = $settings->priceFor($member);

            $wallet = TokenWallet::where('user_id', $member->id)->first();
            $hasBalance = $wallet && TokenMath::cmp($wallet->balance, $price) >= 0;

            if ($hasBalance) {
                $spend = $this->charge($member, $performerUser, $profile, $price);
                $start = $m->current_period_end;
                $end = (clone $start)->addDays((int) config('fanclub.cycle_days'));
                $m->forceFill([
                    'price_tokens' => $price,
                    'price_tier' => $tier,
                    'current_period_start' => $start,
                    'current_period_end' => $end,
                    'grace_until' => null,
                    'last_charge_ledger_id' => $spend->id,
                ])->save();

                return 'renewed';
            }

            // Sem saldo: entra em carência (avisa) ou, se a carência venceu, pausa. Nunca BRL.
            if ($m->grace_until === null) {
                $m->forceFill(['grace_until' => now()->addDays((int) config('fanclub.grace_days'))])->save();

                return 'graced';
            }

            if (now()->greaterThanOrEqualTo($m->grace_until)) {
                $m->forceFill(['status' => FanclubMembership::STATUS_PAUSED, 'paused_at' => now()])->save();

                return 'paused';
            }

            return 'skipped'; // em carência, ainda não venceu
        });
    }

    // ── Serving ───────────────────────────────────────────────────────────────

    /** Este membro tem assinatura ATIVA do fã-clube desta performer? (acesso ao set). */
    public function hasActiveMembership(?User $member, PerformerProfile $profile): bool
    {
        if ($member === null) {
            return false;
        }

        return FanclubMembership::where('member_id', $member->id)
            ->where('performer_profile_id', $profile->id)
            ->where('status', FanclubMembership::STATUS_ACTIVE)
            ->exists();
    }

    // ── Leitura (painel da performer) ───────────────────────────────────────────

    /** Config atual do fã-clube da performer, no shape do editor. */
    public function settingsFor(PerformerProfile $profile): array
    {
        $s = FanclubSettings::where('performer_profile_id', $profile->id)->first();

        return [
            'is_open' => (bool) ($s?->is_open ?? false),
            'price_public_tokens' => $s?->price_public_tokens,
            'vip_enabled' => (bool) ($s?->vip_enabled ?? false),
            'price_vip_tokens' => $s?->price_vip_tokens,
        ];
    }

    /**
     * Lista de assinantes para a performer — o sinal de baleia (docs/FORK_ASSINATURA.md §5).
     * SEMPRE anônimo: FanAlias + selo de tier + FAIXA de gasto. NUNCA member_id, nome, ou
     * número exato. Respeita o PISO de anonimato (abaixo dele, nenhuma linha — só a faixa de
     * contagem), mesma disciplina da lista de seguidores.
     *
     * @return array{below_floor:bool, count_label:string, supporters:array<int,array<string,string>>}
     */
    public function rosterFor(PerformerProfile $profile): array
    {
        $floor = (int) config('interest.anonymity_floor', 5);

        $activeCount = FanclubMembership::where('performer_profile_id', $profile->id)
            ->where('status', FanclubMembership::STATUS_ACTIVE)
            ->count();

        $countLabel = PerformerProfile::followersLabelFor($activeCount);

        // Abaixo do piso: nenhuma linha individual (deanonimização por contagem pequena).
        if ($activeCount < $floor) {
            return ['below_floor' => true, 'count_label' => $countLabel, 'supporters' => []];
        }

        // Gasto acumulado em assinatura POR MEMBRO com esta performer (uma query agregada,
        // sem N+1): soma e contagem dos débitos spend_fanclub_sub referenciando este perfil.
        $spend = TokenLedger::query()
            ->join('token_wallets', 'token_wallets.id', '=', 'token_ledger.wallet_id')
            ->where('token_ledger.entry_type', 'spend_fanclub_sub')
            ->where('token_ledger.reference_type', PerformerProfile::class)
            ->where('token_ledger.reference_id', $profile->id)
            ->groupBy('token_wallets.user_id')
            ->selectRaw('token_wallets.user_id as uid, COUNT(*) as charges, SUM(ABS(token_ledger.amount)) as total')
            ->get()
            ->keyBy('uid');

        $recorrente = (int) config('fanclub.whale.recorrente_charges', 2);
        $alto = (int) config('fanclub.whale.alto_tokens', 300);

        $memberships = FanclubMembership::query()
            ->where('performer_profile_id', $profile->id)
            ->where('status', FanclubMembership::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->limit((int) config('fanclub.roster_limit', 200))
            ->get(['id', 'member_id']);

        // Selo de tier POR MEMBRO numa query só (sem N+1 de assinatura por linha): o
        // Círculo ativo (status active + não vencido) mais recente de cada member_id.
        $memberIds = $memberships->pluck('member_id')->all();
        $slugByUser = Subscription::query()
            ->join('circles', 'circles.id', '=', 'subscriptions.circle_id')
            ->whereIn('subscriptions.user_id', $memberIds)
            ->where('subscriptions.status', 'active')
            ->where('subscriptions.current_period_end', '>', now())
            ->orderByDesc('subscriptions.id')
            ->get(['subscriptions.user_id as uid', 'circles.slug as slug'])
            ->groupBy('uid')
            ->map(fn ($rows) => $rows->first()->slug);

        $rows = $memberships
            ->map(function (FanclubMembership $m) use ($profile, $spend, $slugByUser, $recorrente, $alto) {
                $s = $spend[$m->member_id] ?? null;
                $charges = $s ? (int) $s->charges : 1;
                $total = $s ? (int) round((float) $s->total) : 0;

                $band = match (true) {
                    $total >= $alto => 'alto',
                    $charges >= $recorrente => 'recorrente',
                    default => 'novo',
                };

                $tier = match ($slugByUser[$m->member_id] ?? null) {
                    'founders_circle' => 'FC',
                    'black' => 'Black',
                    null => 'Membro',
                    default => 'Assinante',
                };

                // Só dados ANÔNIMOS: alias + selo + faixa. Nunca member_id/nome/total exato.
                return ['alias' => FanAlias::label($profile->id, $m->member_id), 'tier' => $tier, 'band' => $band];
            })
            ->values()
            ->all();

        return ['below_floor' => false, 'count_label' => $countLabel, 'supporters' => $rows];
    }

    // ── Leitura (lado do membro) ─────────────────────────────────────────────────

    /**
     * Visão do fã-clube da performer para ESTE espectador (card do perfil público). O
     * preço é o DELE (público, ou VIP se Black/FC e a performer ligou). Inclui a grade do
     * set (teaser p/ não-assinante, destravada p/ assinante/dona). Nunca expõe id de outro
     * membro — é tudo sobre o próprio espectador.
     */
    public function memberView(?User $viewer, PerformerProfile $profile): array
    {
        $settings = FanclubSettings::where('performer_profile_id', $profile->id)->first();
        $open = ($settings?->isSubscribable() ?? false) && $this->performerIsReachable($profile);
        $isOwner = $viewer !== null && (int) $profile->user_id === (int) $viewer->id;

        $membership = ($viewer !== null && $viewer->role === 'consumer')
            ? FanclubMembership::where('member_id', $viewer->id)
                ->where('performer_profile_id', $profile->id)
                ->first()
            : null;

        [$price, $tier] = $open ? $settings->priceFor($viewer) : [null, 'public'];

        $isSubscribed = $membership?->isActive() ?? false;

        return [
            'open' => $open,
            'price_tokens' => $open ? (int) $price : null,
            'price_tier' => $tier,
            'is_subscribed' => $isSubscribed,
            'status' => $membership?->status,
            'renews_at' => $isSubscribed ? $membership->current_period_end?->toIso8601String() : null,
            'is_owner' => $isOwner,
            // Mostra a grade quando o clube está aberto, para a dona, OU para quem já
            // assina (o assinante ativo não perde o set se a performer fechar o clube —
            // o acesso por canView depende da assinatura, não de is_open).
            'set' => ($open || $isOwner || $isSubscribed)
                ? app(ContentVisibilityService::class)->fanclubGalleryFor($viewer, $profile)
                : [],
        ];
    }

    /**
     * As assinaturas de fã-clube do membro (tela "Minhas assinaturas"). Ativas e pausadas
     * (as canceladas somem). Cada linha: a performer (pública), status, preço e renovação.
     */
    public function mySubscriptions(User $member): array
    {
        return FanclubMembership::with('performerProfile:id,stage_name,slug')
            ->where('member_id', $member->id)
            ->whereIn('status', [FanclubMembership::STATUS_ACTIVE, FanclubMembership::STATUS_PAUSED])
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (FanclubMembership $m) => [
                'performer' => [
                    'stage_name' => $m->performerProfile?->stage_name,
                    'slug' => $m->performerProfile?->slug,
                ],
                'status' => $m->status,
                'price_tokens' => (int) $m->price_tokens,
                'price_tier' => $m->price_tier,
                'renews_at' => $m->isActive() ? $m->current_period_end?->toIso8601String() : null,
            ])
            ->values()
            ->all();
    }

    // ── Interno ───────────────────────────────────────────────────────────────

    /**
     * Débito do membro (spend_fanclub_sub) + crédito 80/20 à performer (fanclub_sub_credit,
     * rate 'content'). Chamado DENTRO de uma transação com as duas carteiras já travadas.
     * Descrição da performer por FanAlias — nunca id/nome do membro.
     */
    private function charge(User $member, User $performerUser, PerformerProfile $profile, int $price)
    {
        $wallet = TokenWallet::where('user_id', $member->id)->first();
        if (! $wallet || TokenMath::cmp($wallet->balance, $price) < 0) {
            throw FanclubException::insufficientBalance();
        }

        $spend = $this->tokenService->debit(
            $member,
            $price,
            'spend_fanclub_sub',
            PerformerProfile::class,
            $profile->id,
            'Assinatura do fã-clube de '.$profile->stage_name,
        );

        $this->creditPolicy->creditWithSplit(
            $performerUser,
            $price,
            'content',
            'fanclub_sub_credit',
            PerformerProfile::class,
            $profile->id,
            'Fã-clube assinado por '.FanAlias::label($profile->id, $member->id),
        );

        return $spend;
    }

    /** Performer de pé para cobrar/servir? (mesma checagem do ContentVisibilityService). */
    private function performerIsReachable(?PerformerProfile $profile): bool
    {
        if ($profile === null || $profile->trashed()) {
            return false;
        }

        $user = $profile->user()->withTrashed()->first();

        return $user !== null && ! $user->trashed() && $user->status === 'active';
    }
}
