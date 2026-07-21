<?php

namespace App\Services;

use App\Models\Circle;
use App\Models\Follow;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Modo Discreto: o membro continua seguindo e contando para o Piso de Anonimato,
 * mas some da lista de seguidores das performers — e, com isso, deixa de poder
 * receber Interesse Controlado.
 *
 * Vive num service porque a API (Bearer) e a web (sessão) chegam aqui pelos dois
 * controllers: a regra de elegibilidade e a propagação para os follows não podem
 * divergir entre as duas portas.
 */
class DiscreteModeService
{
    /** Tier mínimo do perk. Comparado por RANK, não por slug. */
    public const MIN_TIER = 'black';

    /**
     * Perk de Black/Founders Circle. Checagem por rank de tier, não por lista de
     * slugs: um tier novo acima de Black herda o benefício sem que este arquivo
     * precise ser lembrado.
     */
    public function isEligible(?User $user): bool
    {
        if (! $user || $user->role !== 'consumer') {
            return false;
        }

        $circle = $user->activeCircle();

        return $circle !== null && $this->circleQualifies($circle);
    }

    /** Mesma regra, quando o Círculo já foi carregado (evita requery). */
    public function circleQualifies(?Circle $circle): bool
    {
        if ($circle === null) {
            return false;
        }

        // Comparação (e o fail-closed) em Circle::tierAtLeast — a regra tem uma
        // dona só. A forma anterior era `tierRank() >= array_search(...)`, que
        // falhava ABERTO se 'black' saísse do TIER_ORDER.
        return $circle->tierAtLeast(self::MIN_TIER);
    }

    /**
     * LIGAR exige o tier; DESLIGAR é sempre permitido. Quem ativou como Black e
     * depois lapsou continua discreto de propósito (reexpor por causa de um
     * pagamento atrasado é o erro mais caro), mas prender a pessoa no modo, sem
     * conseguir sair, seria pior ainda.
     */
    public function mayApply(?User $user, bool $desiredValue): bool
    {
        if ($user === null || $user->role !== 'consumer') {
            return false;
        }

        return $desiredValue === false || $this->isEligible($user);
    }

    /**
     * Aplica o valor pedido (ou inverte, se nenhum for informado) e propaga para
     * todos os follows do membro.
     *
     * @return bool o novo valor
     */
    public function apply(User $user, ?bool $desiredValue = null): bool
    {
        $newValue = $desiredValue ?? ! $user->discrete_mode;

        // Idempotente: repetir o mesmo valor (duplo clique, retry de rede, replay
        // do PATCH) não pode virar um desligamento silencioso nem sujar o audit.
        if ($newValue === (bool) $user->discrete_mode) {
            return $newValue;
        }

        DB::transaction(function () use ($user, $newValue) {
            // Atribuição explícita: discrete_mode NÃO é mass-assignable, como
            // preferred_world. É privilégio de tier, não campo de formulário.
            $user->discrete_mode = $newValue;
            $user->save();

            // A cópia em follows precisa acompanhar na mesma transação: se a
            // segunda escrita falhasse sozinha, o membro se veria discreto
            // enquanto seguiria listado para as performers.
            Follow::where('user_id', $user->id)->update(['discrete_mode' => $newValue]);
        });

        // Sem PII e sem a lista de performers afetadas — só o fato.
        Audit::log('member.discrete_mode_toggled', $user, ['enabled' => $newValue]);

        return $newValue;
    }
}
