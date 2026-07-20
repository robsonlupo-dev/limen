<?php

namespace App\Services;

use App\Models\Follow;
use App\Models\PerformerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quem a performer pode ver — e, por consequência, a quem ela pode mandar
 * Interesse Controlado. Fonte única da regra: a tela de seguidores e a validação
 * do envio precisam concordar em cada detalhe, senão o envio vira oráculo para
 * reconstruir exatamente a lista que a tela esconde.
 *
 * Três camadas:
 *  - Piso de Anonimato: lista só a partir de N seguidores (interest.anonymity_floor).
 *  - Modo Discreto: o membro conta para o piso mas nunca é listado.
 *  - Antiguidade de conta: só contas com N dias contam NA DIREÇÃO do piso.
 */
class FollowerVisibilityService
{
    public function floor(): int
    {
        return (int) config('interest.anonymity_floor');
    }

    /**
     * Todos os seguidores ativos, inclusive os discretos: são pessoas reais
     * diluindo a lista. Tirá-los do total deixaria a chegada de um membro
     * discreto visível como um degrau no piso.
     *
     * Este é o número de EXIBIÇÃO (vira faixa na tela) — inclui contas novas.
     * Quem decide o piso é a contagem com corte de idade, abaixo.
     */
    public function totalActiveFollowers(int $profileId): int
    {
        return Follow::where('performer_profile_id', $profileId)
            ->whereHas('user', $this->activeMember())
            ->count();
    }

    /** Follows que podem ser exibidos: membro ativo e não-discreto. */
    public function listableQuery(int $profileId): Builder
    {
        return Follow::where('performer_profile_id', $profileId)
            ->whereHas('user', $this->activeMember())
            // A flag é conferida nos dois lugares (linha do follow e usuário)
            // porque a cópia em follows é denormalizada: se divergirem, vence a
            // mais discreta — errar escondendo é o único erro barato aqui.
            ->where('discrete_mode', false)
            ->whereHas('user', fn ($q) => $q->where('discrete_mode', false));
    }

    /** Idade mínima da conta, em dias, para ela contar na direção do piso. */
    public function floorAccountAgeDays(): int
    {
        return (int) config('interest.anonymity_floor_account_age_days', 7);
    }

    /**
     * Seguidores ativos que CONTAM para o piso: só contas com idade mínima.
     *
     * Mitigação de sybil. Sem o corte, a performer registra 4 contas de
     * consumidor, segue a si mesma com elas e destrava a lista — o próximo
     * seguidor de verdade fica sendo o único nome que ela não plantou, e o piso
     * vira decoração. Contas de véspera são baratas; contas com uma semana de
     * espera, muito menos.
     */
    public function totalActiveFollowersForFloor(int $profileId): int
    {
        return $this->aged(
            Follow::where('performer_profile_id', $profileId)->whereHas('user', $this->activeMember())
        )->count();
    }

    /**
     * A lista pode ser mostrada?
     *
     * Exige o piso nas DUAS contagens, e as duas contam só contas antigas. Só no
     * total não bastava: com 5 seguidores dos quais 4 discretos, a tela
     * renderizaria um único "Membro #123" — o cenário exato que o piso existe
     * para impedir. E só nos visíveis também não: os discretos são gente real
     * diluindo a lista, e ignorá-los no total faria a lista aparecer e sumir
     * conforme eles entram e saem.
     *
     * O corte de idade vale para DESTRAVAR, não para exibir: uma vez que contas
     * antigas atingiram o piso, a lista sai inteira — as novas aparecem nela
     * normalmente (ver listableQuery, sem filtro de idade).
     */
    public function canRevealList(int $profileId): bool
    {
        if ($this->totalActiveFollowersForFloor($profileId) < $this->floor()) {
            return false;
        }

        return $this->aged($this->listableQuery($profileId))->count() >= $this->floor();
    }

    /** Restringe um query de follows a contas com a idade mínima do piso. */
    private function aged(Builder $query): Builder
    {
        $cutoff = now()->subDays($this->floorAccountAgeDays());

        return $query->whereHas('user', fn ($q) => $q->where('created_at', '<=', $cutoff));
    }

    /**
     * O alvo do Interesse é visível para esta performer? Invariante do sistema:
     * ela só envia para quem a tela mostra. Sem isto, um POST direto alcança
     * membros discretos e, abaixo do piso, o par 404/201 varre o espaço de ids e
     * devolve a lista escondida.
     */
    public function canReceiveInterest(PerformerProfile $profile, int $memberId): bool
    {
        if (! $this->canRevealList($profile->id)) {
            return false;
        }

        return $this->listableQuery($profile->id)->where('user_id', $memberId)->exists();
    }

    /** @return \Closure */
    private function activeMember()
    {
        return fn ($query) => $query->where('role', 'consumer')->where('status', 'active');
    }
}
