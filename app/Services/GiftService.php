<?php

namespace App\Services;

use App\Exceptions\GiftException;
use App\Support\TokenMath;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Gift;
use App\Models\GiftSend;
use App\Models\PerformerProfile;
use App\Models\TokenWallet;
use App\Models\User;
use App\Support\Audit;
use App\Support\FanAlias;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Envio de um presente do catálogo da Limen para uma performer (M.13.6). Espelha
 * TipService/ContentUnlockService: trava as DUAS carteiras em ordem crescente de
 * user_id numa transação, cobra o membro (spend_gift) e credita a performer pelo
 * split de evento (gift_credit, 80/20 com applied_rate congelado na linha).
 *
 * Idempotência por idempotency_key (client UUID), escopada por sender_id — o
 * mesmo envio nunca cobra duas vezes, e uma chave não devolve o registro de outro
 * remetente. O preço vem SEMPRE do Gift (catálogo da Limen), nunca do request.
 */
class GiftService
{
    public function __construct(
        private TokenService $tokenService,
        private TokenCreditPolicy $creditPolicy,
    ) {}

    public function send(
        User $member,
        PerformerProfile $performerProfile,
        Gift $gift,
        string $idempotencyKey,
    ): GiftSend {
        // Fast-path idempotência (fora da transação, sem lock). Escopada por
        // sender_id: uma chave só resolve para o registro do PRÓPRIO remetente.
        $existing = GiftSend::where('idempotency_key', $idempotencyKey)
            ->where('sender_id', $member->id)
            ->first();
        if ($existing) {
            return $existing;
        }

        try {
            $giftSend = $this->doSend($member, $performerProfile, $gift, $idempotencyKey);

            // Prova social na live (PR #142): SÓ para um envio novo (não um retorno
            // idempotente) e SÓ durante uma live ativa (gate no LiveOverlayService).
            // Pós-commit — a doSend já retornou com a transação comitada.
            if ($giftSend->wasRecentlyCreated) {
                app(LiveOverlayService::class)->gift($performerProfile, $member, $gift);
            }

            return $giftSend;
        } catch (UniqueConstraintViolationException) {
            // Corrida perdida apesar do lock: a transação reverteu (débito+crédito
            // desfeitos), então o membro NÃO foi cobrado duas vezes. Devolve o
            // envio vencedor DESTE remetente — nunca o de outro (chave é UUID
            // client-random, mas escopamos por sender_id por defesa em profundidade).
            return GiftSend::where('idempotency_key', $idempotencyKey)
                ->where('sender_id', $member->id)
                ->firstOrFail();
        }
    }

    private function doSend(
        User $member,
        PerformerProfile $performerProfile,
        Gift $gift,
        string $idempotencyKey,
    ): GiftSend {
        return DB::transaction(function () use ($member, $performerProfile, $gift, $idempotencyKey) {
            $performerUser = $performerProfile->user;

            // Self-gift barrado ANTES de qualquer lock/débito: a performer não se
            // presenteia (espelha o self-unlock do ContentUnlockService).
            if ($performerUser === null || $performerUser->id === $member->id) {
                throw GiftException::self();
            }

            // Presente inativo não pode ser enviado (catálogo é da Limen).
            if (! $gift->active) {
                throw GiftException::unavailable();
            }

            $price = (int) $gift->price_tokens;

            // Defesa em profundidade: preço do catálogo tem de ser múltiplo de 4
            // (M.13.6). Um catálogo mal-semeado nunca vira débito quebrado.
            if (! $this->creditPolicy->isValidGiftPrice($price)) {
                throw GiftException::unavailable();
            }

            // Carteiras + locks em ordem crescente de user_id (anti-deadlock).
            TokenWallet::firstOrCreate(['user_id' => $member->id], ['balance' => 0]);
            TokenWallet::firstOrCreate(['user_id' => $performerUser->id], ['balance' => 0]);
            TokenWallet::whereIn('user_id', [$member->id, $performerUser->id])
                ->orderBy('user_id')
                ->lockForUpdate()
                ->get();

            // Re-check idempotência DENTRO da transação, sob os locks: dois envios
            // concorrentes do mesmo membro serializam na carteira dele; o segundo
            // relê a linha e devolve, sem cobrar. A UNIQUE é a 2ª linha de defesa.
            $existing = GiftSend::where('idempotency_key', $idempotencyKey)
                ->where('sender_id', $member->id)
                ->first();
            if ($existing) {
                return $existing;
            }

            $memberWallet = TokenWallet::where('user_id', $member->id)->first();
            if (! $memberWallet || TokenMath::cmp($memberWallet->balance, $price) < 0) {
                throw new InsufficientBalanceException($price, $memberWallet?->balance ?? 0);
            }

            // Split por TIPO DE EVENTO: presente é 80/20 desde 21/08/2026 (era 75/25),
            // decimal exato. A taxa vem da config no ato e congela na linha; lançamentos
            // antigos mantêm 75. retido é o COMPLEMENTO: credited + retained == price.
            $split = $this->creditPolicy->applyRate($price, 'gift');
            $performerAmount = $split['credited'];
            $platformAmount = $split['retained'];

            // Débito do membro. reference = o GiftSend (auditoria/reconciliação).
            $spend = $this->tokenService->debit(
                $member,
                $price,
                'spend_gift',
                GiftSend::class,
                null,
                "Presente para {$performerProfile->stage_name}",
            );

            // Crédito da performer pelo split (gift_credit é *_credit → nunca
            // respeita teto). applied_rate (80, ou 75 em lançamentos antigos) congelado
            // na linha. Descrição por FanAlias, nunca o id cru do membro.
            $credit = $this->creditPolicy->creditWithSplit(
                $performerUser,
                $price,
                'gift',
                'gift_credit',
                GiftSend::class,
                null,
                'Presente recebido de '.FanAlias::label($performerProfile->id, $member->id),
            );

            // UNIQUE(idempotency_key): se disparar (corrida além do lock), sobe e a
            // transação inteira reverte — o catch de send() devolve a vencedora.
            $giftSend = GiftSend::create([
                'sender_id' => $member->id,
                'performer_profile_id' => $performerProfile->id,
                'gift_id' => $gift->id,
                'tokens' => $price,
                'performer_amount' => $performerAmount,
                'platform_amount' => $platformAmount,
                'applied_rate' => $split['rate'],
                'idempotency_key' => $idempotencyKey,
                'sender_ledger_id' => $spend->id,
                'performer_ledger_id' => $credit->id,
            ]);

            // Audit ÚNICO: ator = membro (o Audit já grava o ator em user_id),
            // sujeito = o GiftSend. NÃO gravamos um segundo evento do lado da
            // performer com o MESMO subject_id — isso deixaria o par (membro,
            // performer) a um JOIN de distância na tabela que o DeletionService
            // preserva com IP em claro (disciplina do content.unlocked / § 2.5).
            // Nada de sender_id no metadata: o ator já é o membro.
            Audit::log('gift.sent', $giftSend, [
                'gift_id' => $gift->id,
                'performer_profile_id' => $performerProfile->id,
                'tokens' => $price,
                'performer_amount' => $performerAmount,
                'platform_amount' => $platformAmount,
            ]);

            return $giftSend;
        });
    }
}
