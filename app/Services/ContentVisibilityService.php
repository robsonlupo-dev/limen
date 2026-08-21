<?php

namespace App\Services;

use App\Exceptions\ContentException;
use App\Models\ContentUnlock;
use App\Models\PerformerContent;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Support\ContentPresenter;

/**
 * Quem alcança qual peça de conteúdo permanente, resolvido AGORA (M.4/M.13.13).
 * Dona ÚNICA da regra de paywall — o serving dos bytes E o presenter (preview)
 * perguntam A ESTA classe. Se as duas superfícies discordassem, o par (tela mostra
 * / serving nega) viraria oráculo ou furo de paywall, como o StoryVisibilityService
 * documenta. Nenhuma regra de acesso mora no controller nem no Vue.
 *
 * ── Duas perguntas SEPARADAS (M.13.13) ──────────────────────────────────────
 *  1. tierAllows: o TIER do membro ALCANÇA o nível? (open = todos; premium =
 *     Prestige+; exclusive = Black+; fc_only = só FC.) Fail-closed por rank via
 *     Circle::tierAtLeast — nível desconhecido nega.
 *  2. Grátis ou pago: SÓ `open` é grátis, e SÓ para assinante. Não-assinante paga
 *     o Aberto; premium/exclusive/fc_only sempre pagam (para quem o tier alcança).
 *
 * canView (entrega os bytes) exige, para membro: performer DE PÉ + role consumer +
 * (dona OU grátis OU desbloqueado). A checagem de "performer de pé" está AQUI e não
 * só no unlock: conteúdo de performer suspensa/banida por moderação para de ser
 * servido na hora, mesmo para quem já desbloqueou — a mesma disciplina do Story.
 */
class ContentVisibilityService
{
    /**
     * Tier mínimo para COMPRAR (avulso) cada nível. `null` = qualquer membro pode
     * comprar pagando o preço cheio em tokens (inclusive não-assinante). Comparado
     * por RANK (Circle::tierAtLeast, fail-closed). Nível fora deste mapa → nega.
     *
     * Premium virou COMPRA AVULSA em 21/08/2026 (decisão do PO): qualquer membro
     * compra pagando o preço cheio — o incentivo do assinante passou a ser o desconto
     * (na compra de tokens), não o bloqueio. Exclusivo e FC Only CONTINUAM travados
     * por tier (Black+ / FC): aparecem bloqueados, mas não são compráveis avulso.
     */
    public const LEVEL_MIN_TIER = [
        PerformerContent::LEVEL_OPEN => null,
        PerformerContent::LEVEL_PREMIUM => null,
        PerformerContent::LEVEL_EXCLUSIVE => 'black',
        PerformerContent::LEVEL_FC_ONLY => 'founders_circle',
    ];

    /**
     * Rótulo do TIER de assinatura por slug, para o upsell dos tiles bloqueados por
     * tier. Nomeia o TIER (Black, Círculo de Fundadores), NUNCA o nível de conteúdo
     * ("Exclusivo" é nível; o tier que o destrava é Black).
     */
    private const TIER_LABELS = [
        'prestige' => 'Prestige',
        'black' => 'Black',
        'founders_circle' => 'Círculo de Fundadores',
    ];

    /** O membro é a dona desta peça? (nunca é cobrada, sempre vê.) */
    public function isOwner(?User $member, PerformerContent $content): bool
    {
        if ($member === null) {
            return false;
        }

        return (int) $content->performerProfile?->user_id === (int) $member->id;
    }

    /**
     * O TIER do membro alcança o nível? open → sempre; senão exige assinatura de
     * tier suficiente. Nível desconhecido → false (fail-closed, gate de paywall).
     */
    public function tierAllows(?User $member, PerformerContent $content): bool
    {
        if (! array_key_exists($content->access_level, self::LEVEL_MIN_TIER)) {
            return false;
        }

        $minTier = self::LEVEL_MIN_TIER[$content->access_level];

        if ($minTier === null) {
            return true; // Aberto: todos alcançam (não-assinante paga).
        }

        return $member?->activeCircle()?->tierAtLeast($minTier) ?? false;
    }

    /**
     * Os níveis de acesso que o TIER deste espectador ALCANÇA — a forma
     * PAGINÁVEL do `tierAllows` (que é predicado por item). Derivado do MESMO
     * `LEVEL_MIN_TIER`, então filtrar o feed no SQL por `whereIn('access_level',
     * ...)` nunca diverge do que o perfil libera item a item. Sempre inclui
     * `open` (min tier null); não-assinante recebe só ele.
     *
     * Existe para o feed poder paginar corretamente: filtrar por tier DEPOIS do
     * paginate devolveria páginas curtas e contadores errados; filtrar no SQL,
     * antes, mantém a contagem exata.
     *
     * @return array<int, string>
     */
    public function allowedLevelsFor(?User $viewer): array
    {
        $circle = $viewer?->activeCircle();

        return array_keys(array_filter(
            self::LEVEL_MIN_TIER,
            fn (?string $minTier) => $minTier === null || ($circle?->tierAtLeast($minTier) ?? false),
        ));
    }

    /** Grátis para este membro? SÓ Aberto E só para assinante (M.13.13). */
    public function isFreeFor(?User $member, PerformerContent $content): bool
    {
        return $content->access_level === PerformerContent::LEVEL_OPEN
            && $member !== null
            && $member->activeCircle() !== null;
    }

    /** Já pagou o desbloqueio permanente desta peça? */
    public function hasUnlock(?User $member, PerformerContent $content): bool
    {
        if ($member === null) {
            return false;
        }

        return ContentUnlock::where('performer_content_id', $content->id)
            ->where('user_id', $member->id)
            ->exists();
    }

    /**
     * Este membro pode VER os bytes desta peça, neste instante?
     *
     * Dona sempre vê. Para membro: performer de pé + role consumer + (grátis OU
     * desbloqueado). O guard de "performer de pé" e de role é o que o serving
     * exige e o que faltaria se isto fosse só `owner OR free OR unlock` (achado B1
     * da revisão): peça de performer banida NÃO é servível nem para quem pagou.
     */
    public function canView(?User $member, PerformerContent $content): bool
    {
        // Só peça PRONTA tem bytes servíveis — vídeo em processing/failed não é
        // servível para NINGUÉM (nem para a dona: a gestão mostra o STATUS, não a
        // imagem). Vale antes do atalho da dona, de propósito. Foto nasce ready.
        if (! $content->isReady()) {
            return false;
        }

        if ($this->isOwner($member, $content)) {
            return true;
        }

        if ($member === null || $member->role !== 'consumer') {
            return false;
        }

        if (! $this->performerIsReachable($content->performerProfile)) {
            return false;
        }

        return $this->isFreeFor($member, $content) || $this->hasUnlock($member, $content);
    }

    /**
     * Por que este membro NÃO pode desbloquear? `null` = pode. É o que decide o
     * 404/403/422 do endpoint de unlock — a escolha é da REGRA, não do controller.
     */
    public function denialForUnlock(User $member, PerformerContent $content): ?string
    {
        if ($this->isOwner($member, $content)) {
            return ContentException::SELF;
        }

        // Peça não pronta (vídeo em processing/failed) não é desbloqueável —
        // trata como fora do ar (defesa; o membro nem a vê na galeria/feed).
        if (! $content->isReady()
            || $member->role !== 'consumer'
            || ! $this->performerIsReachable($content->performerProfile)) {
            return ContentException::OFFLINE;
        }

        // Já grátis (assinante no Aberto) ou já desbloqueado: no-op, nada a cobrar.
        if ($this->isFreeFor($member, $content) || $this->hasUnlock($member, $content)) {
            return ContentException::ALREADY;
        }

        // Tier insuficiente → 403 (upsell). Aberto para não-assinante passa daqui
        // (tierAllows=true) e segue para o pagamento.
        if (! $this->tierAllows($member, $content)) {
            return ContentException::FORBIDDEN;
        }

        return null;
    }

    public function canUnlock(User $member, PerformerContent $content): bool
    {
        return $this->denialForUnlock($member, $content) === null;
    }

    /**
     * Estado da peça para ESTE espectador (presenter). Dado do próprio membro
     * sobre si — nunca vai para superfície da performer.
     */
    public function stateFor(?User $member, PerformerContent $content): string
    {
        if ($this->isOwner($member, $content)) {
            return 'owner';
        }

        if ($this->hasUnlock($member, $content)) {
            return 'unlocked';
        }

        if ($this->isFreeFor($member, $content)) {
            return 'free';
        }

        return 'locked';
    }

    /**
     * A galeria de conteúdo permanente que ESTE espectador vê no perfil, já no shape
     * do ContentPresenter (locked/price/image_url/can_unlock/required_tier_label) — a
     * MESMA fonte do serving, para presenter e listagem nunca divergirem.
     *
     * TODOS os níveis PRONTOS aparecem (mudança de 21/08/2026): antes, peça acima do
     * tier SUMIA da galeria — o membro nem sabia que existia, o que escondia o valor
     * da assinatura em vez de protegê-lo. Agora aparecem como TILE BLOQUEADO (imagem
     * NUNCA servida a quem não pode ver — `image_url` vem null, o front desenha
     * placeholder). O que muda por nível é só o que o tile OFERECE: Premium →
     * comprável por qualquer um; Exclusivo/FC Only → bloqueado com upsell do tier.
     * Ordem: mais recente primeiro (id desc).
     *
     * @return array<int, array<string, mixed>>
     */
    public function galleryFor(?User $viewer, PerformerProfile $profile): array
    {
        if (! $this->performerIsReachable($profile)) {
            return [];
        }

        return PerformerContent::query()
            ->where('performer_profile_id', $profile->id)
            ->ready() // vídeo em processing/failed não aparece na vitrine
            ->orderByDesc('id')
            ->get()
            ->map(fn (PerformerContent $content) => ContentPresenter::one($content, $viewer))
            ->values()
            ->all();
    }

    /**
     * Rótulo do TIER de assinatura que destrava este nível — para o upsell do tile
     * bloqueado POR TIER (Exclusivo/FC Only sem o Círculo). `null` quando o espectador
     * já vê, PODE comprar avulso (Aberto/Premium, ou o tier alcança), ou é Aberto.
     * Nomeia o TIER (Black, Círculo de Fundadores), NUNCA o nível de conteúdo.
     */
    public function upsellTierLabel(?User $viewer, PerformerContent $content): ?string
    {
        // Já vê, ou o tier alcança (então pode comprar avulso) → sem upsell de tier.
        if ($this->canView($viewer, $content) || $this->tierAllows($viewer, $content)) {
            return null;
        }

        $minTier = self::LEVEL_MIN_TIER[$content->access_level] ?? null;

        return $minTier === null ? null : (self::TIER_LABELS[$minTier] ?? null);
    }

    /**
     * A performer está de pé para ter conteúdo servido? Mesma checagem (e razão)
     * de StoryVisibilityService/MemberPhotoService::performerIsReachable.
     */
    public function performerIsReachable(?PerformerProfile $profile): bool
    {
        if ($profile === null || $profile->trashed()) {
            return false;
        }

        $user = $profile->user()->withTrashed()->first();

        return $user !== null && ! $user->trashed() && $user->status === 'active';
    }
}
