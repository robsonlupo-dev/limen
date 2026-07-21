<?php

namespace App\Services;

use App\Models\Circle;
use App\Models\User;
use App\Support\Audit;

/**
 * Perks de privacidade de Black / Founders Circle.
 *
 * Três chaves, uma regra: Ghost Mode (a visita ao perfil não é registrada),
 * Status Invisível (a presença do membro não é exposta) e Read Receipts (a
 * leitura da mensagem não é confirmada para quem enviou).
 *
 * Fonte única da elegibilidade, como FollowerVisibilityService é a fonte única
 * do Piso de Anonimato. Se a tela de configurações e o ponto de aplicação
 * discordarem sobre quem tem o perk, o perk vira decorativo: o toggle diz
 * "invisível" e a visita é gravada assim mesmo.
 *
 * A checagem é por RANK de tier (>= black), não por lista de slugs — mesma
 * escolha do DiscreteModeService: um tier novo acima de Black herda os perks
 * sem que este arquivo precise ser lembrado.
 */
class PrivacyPerkService
{
    public const GHOST_MODE = 'ghost_mode';

    public const INVISIBLE_STATUS = 'invisible_status';

    public const READ_RECEIPTS = 'read_receipts_enabled';

    public const PERKS = [self::GHOST_MODE, self::INVISIBLE_STATUS, self::READ_RECEIPTS];

    /** Tier mínimo que dá direito aos perks. Comparado por RANK, não por slug. */
    public const MIN_TIER = 'black';

    /**
     * Valor do perk quando o membro nunca escolheu à mão.
     *
     * Black/FC nasce no estado PRIVADO — é isso que o salto de preço compra, e
     * um perk que só existe depois de achar o toggle não se paga. Para os
     * demais tiers o padrão é o comportamento normal da plataforma.
     *
     * `read_receipts_enabled` é o único invertido: privado aqui é `false`
     * (não confirma leitura), enquanto nos outros dois privado é `true`.
     */
    private const PRIVATE_DEFAULT = [
        self::GHOST_MODE => true,
        self::INVISIBLE_STATUS => true,
        self::READ_RECEIPTS => false,
    ];

    private const PUBLIC_DEFAULT = [
        self::GHOST_MODE => false,
        self::INVISIBLE_STATUS => false,
        self::READ_RECEIPTS => true,
    ];

    /** O tier atual dá direito aos perks? */
    public function isEligible(?User $user): bool
    {
        if (! $user || $user->role !== 'consumer') {
            return false;
        }

        return $this->circleQualifies($user->activeCircle());
    }

    /** Mesma regra, com o Círculo já carregado (evita requery no share() do Inertia). */
    public function circleQualifies(?Circle $circle): bool
    {
        if ($circle === null) {
            return false;
        }

        // Comparação (e o fail-closed) em Circle::tierAtLeast — a regra tem uma
        // dona só. A forma anterior era `tierRank() >= array_search(...)`, que
        // falhava ABERTO se MIN_TIER saísse do TIER_ORDER.
        return $circle->tierAtLeast(self::MIN_TIER);
    }

    /**
     * Valor vigente do perk: a escolha explícita, ou o padrão do tier.
     *
     * NOTA PARA O PO — divergência conhecida com o Modo Discreto: lá, quem
     * ativou e depois lapsou o pagamento CONTINUA discreto (não reexpomos por
     * atraso). Aqui, quem nunca tocou no toggle e lapsa volta ao padrão
     * público, porque não há escolha registrada para preservar. Quem clicou
     * uma vez fica com o valor escolhido para sempre, inclusive após o lapso.
     * Deixar o padrão "grudar" no lapso exigiria materializar a escolha na
     * ativação da assinatura — decisão de produto, não de implementação.
     *
     * @param  bool|null  $eligible  elegibilidade já resolvida pelo chamador, se
     *                               ele a tiver em mãos — evita reconsultar o
     *                               Círculo três vezes ao montar o estado.
     */
    public function effective(?User $user, string $perk, ?bool $eligible = null): bool
    {
        $this->assertKnownPerk($perk);

        if (! $user || $user->role !== 'consumer') {
            return self::PUBLIC_DEFAULT[$perk];
        }

        $explicit = $user->getAttribute($perk);

        if ($explicit !== null) {
            return (bool) $explicit;
        }

        return ($eligible ?? $this->isEligible($user))
            ? self::PRIVATE_DEFAULT[$perk]
            : self::PUBLIC_DEFAULT[$perk];
    }

    /**
     * Pode aplicar este valor?
     *
     * Mover para o lado PRIVADO exige o tier; voltar para o lado público é
     * sempre permitido. Mesma assimetria do Modo Discreto, pela mesma razão:
     * ninguém pode ficar preso num modo sem conseguir sair, mas também não se
     * compra privacidade sem assinar.
     */
    public function mayApply(?User $user, string $perk, bool $desiredValue): bool
    {
        $this->assertKnownPerk($perk);

        if (! $user || $user->role !== 'consumer') {
            return false;
        }

        if ($desiredValue === self::PUBLIC_DEFAULT[$perk]) {
            return true;
        }

        return $this->isEligible($user);
    }

    /**
     * Grava a escolha explícita. Idempotente: repetir o mesmo valor (duplo
     * clique, retry de rede, replay do PATCH) não gera linha de auditoria nova.
     */
    public function apply(User $user, string $perk, bool $desiredValue): bool
    {
        $this->assertKnownPerk($perk);

        if ($user->getAttribute($perk) === $desiredValue) {
            return $desiredValue;
        }

        // Atribuição explícita: nenhum dos três está no $fillable. São
        // privilégios de tier, não campos de formulário.
        $user->setAttribute($perk, $desiredValue);
        $user->save();

        // Sem PII: só qual perk mudou e para quanto.
        Audit::log('member.privacy_perk_toggled', $user, ['perk' => $perk, 'enabled' => $desiredValue]);

        return $desiredValue;
    }

    /**
     * Estado dos três perks para o front (tela de configurações + share do
     * Inertia). `eligible` controla se o toggle é operável ou aparece bloqueado
     * com o convite para assinar.
     *
     * @param  bool|null  $eligible  elegibilidade já resolvida, se o chamador a
     *                               tiver — o share() do Inertia roda em TODA
     *                               resposta e já carregou o Círculo.
     * @return array{eligible:bool,ghost_mode:bool,invisible_status:bool,read_receipts_enabled:bool}
     */
    public function stateFor(?User $user, ?bool $eligible = null): array
    {
        $eligible ??= $this->isEligible($user);

        return [
            'eligible' => $eligible,
            self::GHOST_MODE => $this->effective($user, self::GHOST_MODE, $eligible),
            self::INVISIBLE_STATUS => $this->effective($user, self::INVISIBLE_STATUS, $eligible),
            self::READ_RECEIPTS => $this->effective($user, self::READ_RECEIPTS, $eligible),
        ];
    }

    private function assertKnownPerk(string $perk): void
    {
        if (! in_array($perk, self::PERKS, true)) {
            throw new \InvalidArgumentException("Perk de privacidade desconhecido: {$perk}");
        }
    }
}
