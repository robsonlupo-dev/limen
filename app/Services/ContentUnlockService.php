<?php

namespace App\Services;

use App\Exceptions\ContentException;
use App\Support\TokenMath;
use App\Exceptions\InsufficientBalanceException;
use App\Models\ContentUnlock;
use App\Models\PerformerContent;
use App\Models\TokenWallet;
use App\Models\User;
use App\Support\Audit;
use App\Support\FanAlias;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * O desbloqueio PAGO e PERMANENTE de uma peça (M.4). Espelha o TipService: trava
 * as DUAS carteiras em ordem crescente de user_id numa transação, cobra o membro
 * (spend_content) e credita a performer pelo split de evento (content_credit,
 * 80/20 com applied_rate congelado). Uma linha content_unlocks por par (peça,
 * membro), UNIQUE — nunca cobra duas vezes.
 */
class ContentUnlockService
{
    public function __construct(
        private TokenService $tokenService,
        private TokenCreditPolicy $creditPolicy,
        private ContentVisibilityService $visibility,
    ) {}

    public function unlock(User $member, PerformerContent $content): ContentUnlock
    {
        $performerUser = $content->performerProfile?->user;

        // Self-unlock barrado ANTES de qualquer lock/débito (achado S1) — a dona
        // não paga a própria peça, mesmo que role/estado batessem.
        if ($performerUser === null || $performerUser->id === $member->id) {
            throw ContentException::self();
        }

        // Fast-path: já desbloqueado (fora da transação, sem lock).
        $existing = ContentUnlock::where('performer_content_id', $content->id)
            ->where('user_id', $member->id)
            ->first();
        if ($existing) {
            return $existing;
        }

        try {
            return $this->doUnlock($member, $content, $performerUser);
        } catch (UniqueConstraintViolationException) {
            // Corrida perdida apesar do lock: a transação reverteu (débito+crédito
            // desfeitos), então o membro NÃO foi cobrado duas vezes. Devolve o
            // desbloqueio vencedor — o resultado que ele queria.
            return ContentUnlock::where('performer_content_id', $content->id)
                ->where('user_id', $member->id)
                ->firstOrFail();
        }
    }

    private function doUnlock(User $member, PerformerContent $content, User $performerUser): ContentUnlock
    {
        return DB::transaction(function () use ($member, $content, $performerUser) {
            TokenWallet::firstOrCreate(['user_id' => $member->id], ['balance' => 0]);
            TokenWallet::firstOrCreate(['user_id' => $performerUser->id], ['balance' => 0]);

            // Locks em ordem crescente de user_id (anti-deadlock, como o Tip).
            TokenWallet::whereIn('user_id', [$member->id, $performerUser->id])
                ->orderBy('user_id')
                ->lockForUpdate()
                ->get();

            // Re-check da idempotência DENTRO da transação, sob os locks (achado
            // B2): dois unlocks concorrentes do mesmo membro serializam na carteira
            // dele; o segundo relê a linha e devolve, sem cobrar. A UNIQUE é a 2ª
            // linha de defesa.
            $existing = ContentUnlock::where('performer_content_id', $content->id)
                ->where('user_id', $member->id)
                ->first();
            if ($existing) {
                return $existing;
            }

            // Regra de acesso (404/403/422) — dona única, reavaliada sob o lock.
            // As mensagens vivem nos factories da ContentException (uma fonte só).
            if ($reason = $this->visibility->denialForUnlock($member, $content)) {
                throw match ($reason) {
                    ContentException::FORBIDDEN => ContentException::forbidden(),
                    ContentException::ALREADY => ContentException::already(),
                    ContentException::SELF => ContentException::self(),
                    default => ContentException::offline(),
                };
            }

            $price = (int) $content->price_tokens;

            $memberWallet = TokenWallet::where('user_id', $member->id)->first();
            if (! $memberWallet || TokenMath::cmp($memberWallet->balance, $price) < 0) {
                throw new InsufficientBalanceException($price, $memberWallet?->balance ?? 0);
            }

            // Débito do membro. reference = a PEÇA (auditoria/reconciliação, S2).
            $spend = $this->tokenService->debit(
                $member,
                $price,
                'spend_content',
                PerformerContent::class,
                $content->id,
                "Desbloqueio de conteúdo #{$content->id}",
            );

            // Crédito da performer pelo split por evento (M.13.6): 80/20,
            // applied_rate=80 congelado na linha. Descrição por FanAlias, nunca o
            // id cru do membro. Nunca respeita teto (content_credit é *_credit).
            $credit = $this->creditPolicy->creditWithSplit(
                $performerUser,
                $price,
                'content',
                'content_credit',
                PerformerContent::class,
                $content->id,
                'Conteúdo desbloqueado por '.FanAlias::label($content->performer_profile_id, $member->id),
            );

            // UNIQUE(peça, membro): se disparar (corrida além do lock), sobe e a
            // transação inteira reverte — o catch de unlock() devolve a vencedora.
            $unlock = new ContentUnlock;
            $unlock->performer_content_id = $content->id;
            $unlock->user_id = $member->id;
            $unlock->tokens_paid = $price;
            $unlock->spend_ledger_id = $spend->id;
            $unlock->credit_ledger_id = $credit->id;
            $unlock->unlocked_at = now();
            $unlock->save();

            // Audit: ator = membro, sujeito = a PEÇA. Sem member_id em claro além do
            // ator (audit já guarda o ator; não duplicamos como sujeito).
            Audit::log('content.unlocked', $content, [
                'tokens_paid' => $price,
                'access_level' => $content->access_level,
            ]);

            return $unlock;
        });
    }
}
