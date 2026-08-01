<?php

namespace App\Services;

use App\Exceptions\BoostException;
use App\Models\PerformerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Boost pago (Sprint 11) — dona única da regra. A performer gasta tokens para
 * destacar o perfil no topo do catálogo por uma janela de tempo.
 *
 * Monetização INDIRETA: o boost não credita ninguém (100% plataforma, como o
 * desbloqueio de Interesse). A receita vem de a performer precisar COMPRAR
 * tokens (PIX) para ter o que gastar aqui.
 *
 * O débito é uma linha nova no `token_ledger` (`spend_boost`), NUNCA um UPDATE
 * de saldo (princípio nº 2 do CLAUDE.md) — quem grava é o TokenService, dentro
 * da mesma transação que trava o perfil. Toda a política (custo, duração,
 * escassez de vagas) mora no config/boost.php e é lida por aqui.
 */
class BoostService
{
    public function __construct(private TokenService $tokenService) {}

    public function cost(): int
    {
        return (int) config('boost.cost_tokens');
    }

    public function durationHours(): int
    {
        return (int) config('boost.duration_hours');
    }

    public function maxActive(): int
    {
        return (int) config('boost.max_active');
    }

    public function isActive(PerformerProfile $profile): bool
    {
        return $profile->isBoosted();
    }

    /** Quantos perfis estão boostados AGORA — os slots ocupados. */
    public function activeBoostedCount(): int
    {
        return PerformerProfile::query()->boosted()->count();
    }

    /** Vagas de destaque ainda livres (nunca negativo). */
    public function availableSlots(): int
    {
        return max(0, $this->maxActive() - $this->activeBoostedCount());
    }

    /**
     * Ativa o destaque: debita os tokens e carimba `boosted_until`.
     *
     * A ordem das guardas importa e é toda dentro de UMA transação que começa
     * travando a linha do perfil (`lockForUpdate`):
     *
     *  1. **Elegível?** Só perfil verificado + conta ativa entra no catálogo —
     *     boostar um perfil fora do catálogo queimaria tokens por nada. Reconfere
     *     pela chave (o `$profile` recebido pode estar defasado), como o
     *     FavoriteService::assertProfileIsLive.
     *  2. **Já boostado?** Rejeita — não empilha. É o lock do perfil + esta
     *     checagem que tornam o duplo-submit seguro: o segundo request espera o
     *     primeiro, relê "já boostado" e recusa ANTES de debitar. Sem isso, dois
     *     cliques rápidos debitariam duas vezes e estenderiam o destaque.
     *  3. **Tem vaga?** Teto global de perfis simultâneos (escassez = valor).
     *  4. **Debita** via TokenService (append-only; lança
     *     InsufficientBalanceException se o saldo não cobre — ANTES de carimbar,
     *     então saldo insuficiente nunca deixa um boost pela metade).
     *  5. **Carimba** `boosted_until = now() + duração`.
     *
     * @throws BoostException perfil inelegível, já boostado ou sem vaga
     * @throws \App\Exceptions\InsufficientBalanceException saldo insuficiente
     */
    public function boost(PerformerProfile $profile, User $user): void
    {
        DB::transaction(function () use ($profile, $user) {
            // Trava a linha do perfil como primeira instrução: serializa boosts
            // concorrentes do MESMO perfil (o double-submit) e mantém a leitura
            // de `isBoosted()` abaixo fresca (imune ao snapshot de REPEATABLE
            // READ).
            $locked = PerformerProfile::query()
                ->whereKey($profile->id)
                ->lockForUpdate()
                ->first();

            // 1. Elegibilidade: verificada + conta ativa (o recorte do catálogo).
            if ($locked === null || ! $this->isEligible($locked)) {
                throw BoostException::notEligible();
            }

            // 2. Já em destaque — não empilha.
            if ($locked->isBoosted()) {
                throw BoostException::alreadyBoosted();
            }

            // 3. Vaga disponível. Teto GLOBAL: o lock do perfil não serializa
            //    boosts de perfis DIFERENTES, então sob concorrência alta o teto
            //    é elástico por um (dois perfis podem passar juntos e chegar a
            //    max+1). É um limite de negócio, não de dinheiro nem de segurança
            //    — o débito e o "não empilha" (que são o que protege o saldo)
            //    seguem exatos pelo lock do perfil. Aceito como soft-cap; fechar
            //    de vez exigiria serializar todos os boosts num lock global, caro
            //    para o que o teto vale.
            if ($this->activeBoostedCount() >= $this->maxActive()) {
                throw BoostException::noSlots($this->maxActive());
            }

            // 4. Débito append-only. Lança InsufficientBalanceException se o saldo
            //    não cobre — e como é ANTES do carimbo, a falha não deixa destaque
            //    sem pagamento.
            $this->tokenService->debit(
                $user,
                $this->cost(),
                'spend_boost',
                PerformerProfile::class,
                $locked->id,
                "Destaque do perfil #{$locked->id}",
            );

            // 5. Carimba o FIM do destaque. forceFill: `boosted_until` está fora
            //    do $fillable (só nasce aqui, nunca de payload de massa).
            $locked->forceFill([
                'boosted_until' => now()->addHours($this->durationHours()),
            ])->save();

            // Mantém o objeto recebido em sincronia com o que foi persistido, para
            // o chamador ler o novo estado sem um refresh.
            $profile->setAttribute('boosted_until', $locked->boosted_until);
        });
    }

    /**
     * Perfil elegível ao destaque = o mesmo recorte do catálogo: conta `active` e
     * `is_verified`. Reconsulta pela chave em vez de confiar no objeto recebido —
     * ele pode ter sido carregado antes de a conta suspender.
     */
    private function isEligible(PerformerProfile $profile): bool
    {
        return PerformerProfile::query()
            ->whereKey($profile->getKey())
            ->where('is_verified', true)
            ->whereHas('user', fn (Builder $q) => $q->where('status', 'active'))
            ->exists();
    }
}
