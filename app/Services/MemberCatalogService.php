<?php

namespace App\Services;

use App\Models\PerformerInterest;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Support\ActivitySlot;
use App\Support\FanAlias;
use App\Support\MemberDisplayName;
use App\Support\NewBadge;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de MEMBROS para a performer (Sprint 16). É a vitrine invertida: a
 * performer navega membros para sinalizar interesse (Interesse Controlado
 * invertido — ela sinaliza, o membro decide se paga para abrir o chat).
 *
 * DONA ÚNICA de "quem aparece". A regra de visibilidade vive aqui, em SQL, e a
 * mesma query alimenta a LISTA e a RESOLUÇÃO do handle no envio de interesse —
 * se as duas discordassem, o par 404/201 do envio viraria oráculo para
 * reconstruir quem a lista esconde (a disciplina do FollowerVisibilityService).
 *
 * Privacidade (LOCKED — CLAUDE.md M.13.10 e a assimetria membro→performer):
 *  - a performer vê só FanAlias (label 4 díg. + handle 16 hex), faixa de
 *    atividade e nada mais. NUNCA nome, e-mail, tier/Círculo, saldo ou id;
 *  - Modo Discreto exclui do catálogo (invisível às performers, override da
 *    visibilidade);
 *  - Status Invisível não exclui, mas suprime a faixa de atividade (presença
 *    não exposta);
 *  - visibilidade efetiva = explícita, senão Black/FC ocultos por padrão
 *    (User::isVisibleToPerformers, aqui espelhada em SQL para evitar N+1).
 */
class MemberCatalogService
{
    /** Tiers de alta privacidade: ocultos por padrão quando a escolha é `null`. */
    private const HIGH_PRIVACY_TIERS = ['black', 'founders_circle'];

    private const PER_PAGE = 24;

    public function __construct(private PerformerHeartService $hearts) {}

    /**
     * Query base dos membros VISÍVEIS. Fonte única — a lista e a resolução de
     * handle passam por aqui, então nunca divergem.
     */
    public function visibleQuery(): Builder
    {
        return User::query()
            ->where('users.role', 'consumer')
            ->where('users.status', 'active')
            // Conta verificada só: throwaway não é exposto à performer.
            ->whereNotNull('users.email_verified_at')
            // Modo Discreto = invisível às performers. Exclui do catálogo mesmo
            // com visibilidade ON — é o override que o perk (Black/FC) promete.
            ->where('users.discrete_mode', false)
            // Visibilidade efetiva: explícito `true`, OU `null` e NÃO Black/FC.
            // Espelha User::isVisibleToPerformers() em SQL (sem N+1 de assinatura).
            ->where(function (Builder $q) {
                $q->where('users.visible_to_performers', true)
                    ->orWhere(function (Builder $q2) {
                        $q2->whereNull('users.visible_to_performers')
                            ->whereNotExists(function ($sub) {
                                $sub->select(DB::raw(1))
                                    ->from('subscriptions')
                                    ->join('circles', 'circles.id', '=', 'subscriptions.circle_id')
                                    ->whereColumn('subscriptions.user_id', 'users.id')
                                    ->where('subscriptions.status', 'active')
                                    ->where('subscriptions.current_period_end', '>', now())
                                    ->whereIn('circles.slug', self::HIGH_PRIVACY_TIERS);
                            });
                    });
            });
    }

    /**
     * Página do catálogo, já mascarada para a performer. Ordena por id desc
     * (contas mais novas primeiro): estável para paginar e SEM sinal temporal
     * de atividade — ordenar por recência devolveria, pela POSIÇÃO, o que a
     * faixa grossa de atividade existe para não datar.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function page(PerformerProfile $performerProfile, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $paginator = $this->visibleQuery()
            // avatar_path/avatar_token alimentam a foto do membro no card
            // (fix/member-photo-and-crop) via User::avatarUrl() no mask — sem
            // elas no select, o accessor leria null e a foto sumiria.
            // profile_visible: opt-in mestre da galeria/perfil (Opção B) — decide
            // se o card usa a foto da galeria e se o perfil é clicável.
            ->select('users.id', 'users.last_login_at', 'users.invisible_status', 'users.created_at',
                'users.avatar_path', 'users.avatar_token', 'users.nickname', 'users.profile_visible')
            ->orderByDesc('users.id')
            ->paginate($perPage)
            ->withQueryString();

        // Estados por card em queries para a página inteira (não N):
        //  - `interest_sent`: dentro do cooldown do Interesse Controlado pago;
        //  - `hearted`: a performer já deu coração a este membro;
        //  - `primaryPhoto`: a foto principal APROVADA da galeria (Opção B).
        $memberIds = $paginator->getCollection()->pluck('id')->all();
        $recent = $this->recentInterestMemberIds($performerProfile, $memberIds);
        $hearted = $this->hearts->heartedMemberIds($performerProfile, $memberIds);
        $primaryPhotos = $this->primaryPhotoUrls($memberIds);

        $paginator->setCollection(
            $paginator->getCollection()->map(
                fn (User $member) => $this->mask(
                    $performerProfile,
                    $member,
                    in_array($member->id, $recent, true),
                    in_array($member->id, $hearted, true),
                    $primaryPhotos[$member->id] ?? null,
                ),
            )
        );

        return $paginator;
    }

    /**
     * Ids dos membros que a performer poderia ver AGORA — o conjunto contra o
     * qual o handle é resolvido no envio de interesse. Mesma query da lista, por
     * construção. Carrega só a coluna `id`.
     *
     * Teto de escala conhecido: resolver o handle itera este conjunto com um
     * HMAC por candidato (FanAlias::resolveHandle). No volume de lançamento é
     * barato; se a base de membros visíveis crescer para dezenas de milhares,
     * troca-se por uma resolução indexada. Registrado para não ser redescoberto.
     *
     * @return array<int, int>
     */
    public function visibleMemberIds(): array
    {
        return $this->visibleQuery()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Payload mascarado de um membro para a performer. Campos EXPLÍCITos — nunca
     * serializa o User, para nenhum atributo (nome, e-mail, tier, saldo) pegar
     * carona.
     *
     * @return array<string, mixed>
     */
    private function mask(PerformerProfile $performerProfile, User $member, bool $interestSent, bool $hearted, ?string $primaryPhotoUrl = null): array
    {
        $handle = FanAlias::handle($performerProfile->id, $member->id);

        return [
            // Apelido do membro se ele escolheu; senão o FanAlias de sempre
            // (feat/member-nickname). A CHAVE de resolução do alvo (member_handle)
            // segue sendo o FanAlias — o apelido é só o rótulo exibido.
            'fan_alias_label' => MemberDisplayName::for($member->nickname, $performerProfile->id, $member->id, 'Membro #'),
            'member_handle' => $handle,
            // Foto de perfil do membro (fix/member-photo-and-crop). Decisão do PO
            // (ago/2026): o membro passou a ter avatar, e ELE é exibido à performer
            // no catálogo — reverte o antigo "membro não tem avatar no produto".
            // Sem foto → null → o MemberCard cai na silhueta (o padrão de antes).
            //
            // A URL é chaveada no avatar_token OPACO, NUNCA no member_id — o
            // FanAlias acima segue escondendo o id; a foto não o reintroduz na URL.
            // RESSALVA registrada: o ROSTO é uma chave de join global entre
            // performers (mesma natureza da Foto Efêmera) — exposição consentida
            // pela visibilidade do catálogo (só entra quem optou), não anonimato.
            //
            // Galeria de perfil (Opção B): se o membro ligou o perfil visível E tem
            // foto principal aprovada, o card usa a foto DA GALERIA; senão cai no
            // avatar (comportamento de antes) e, sem avatar, na silhueta. O gate de
            // profile_visible é aplicado ao MONTAR a URL em primaryPhotoUrls().
            'avatar_url' => $primaryPhotoUrl ?? $member->avatarUrl(),
            // Perfil clicável (Opção B): só quando o membro ligou o perfil visível.
            // Null → o card não navega para uma página (cai no modal, como hoje). A
            // rota é chaveada no handle OPACO, nunca no member_id.
            'profile_url' => $member->profile_visible ? route('performer.members.profile', ['handle' => $handle]) : null,
            // Faixa grossa, nunca relógio; suprimida para quem tem Status
            // Invisível (presença não exposta). Sinal = last_login_at.
            'activity_label' => ActivitySlot::for($member->invisible_status ? null : $member->last_login_at),
            // Selo "Novo" (feat/activity-badges): BOOLEANO derivado do `created_at`
            // da conta, nunca a data. A janela de 7 dias é mais grossa que a faixa
            // de atividade, então não data a criação ao minuto; e o catálogo já
            // ordena por id desc (mais novos no topo), então o selo só rotula o que
            // a posição implica. NÃO é suprimido por Status Invisível — é idade de
            // conta, não presença. NewBadge é a dona única da janela.
            'is_new' => NewBadge::isNew($member->created_at),
            'interest_sent' => $interestSent,
            // A performer já curtiu este membro? O card já vem com o coração cheio.
            'hearted' => $hearted,
        ];
    }

    /**
     * URL da foto principal APROVADA da galeria de cada membro (Opção B), só para
     * quem ligou o perfil visível. Uma query para a página inteira (não N):
     * carrega as aprovadas dos membros da página, agrupa por membro e escolhe a
     * principal (senão a mais antiga). O gate de profile_visible entra aqui — sem
     * ele ligado, o card não usa a foto da galeria.
     *
     * @param  array<int, int>  $memberIds
     * @return array<int, string>  member_id => URL assinada
     */
    private function primaryPhotoUrls(array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        // Só de membros com perfil visível — os demais não expõem a galeria.
        $visibleIds = User::whereIn('id', $memberIds)
            ->where('profile_visible', true)
            ->pluck('id')
            ->all();

        if ($visibleIds === []) {
            return [];
        }

        $urls = [];

        \App\Models\MemberGalleryPhoto::whereIn('user_id', $visibleIds)
            ->approved()
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->groupBy('user_id')
            ->each(function ($photos, $userId) use (&$urls) {
                // groupBy preserva a ordem: a primeira é a principal (ou a mais antiga).
                $url = $photos->first()?->mediaUrl();
                if ($url !== null) {
                    $urls[(int) $userId] = $url;
                }
            });

        return $urls;
    }

    /**
     * Membros (do conjunto dado) que já receberam interesse desta performer
     * dentro do cooldown — reenviar seria recusado, então o botão já vem travado.
     *
     * @param  array<int, int>  $memberIds
     * @return array<int, int>
     */
    private function recentInterestMemberIds(PerformerProfile $performerProfile, array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        $cooldownDays = (int) config('interest.cooldown_days');

        return PerformerInterest::where('performer_profile_id', $performerProfile->id)
            ->whereIn('member_id', $memberIds)
            ->where('sent_at', '>=', now()->subDays($cooldownDays))
            ->pluck('member_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
