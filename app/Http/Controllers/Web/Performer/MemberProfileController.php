<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ChatService;
use App\Services\MemberCatalogService;
use App\Services\MemberGalleryAccessService;
use App\Services\MemberGalleryService;
use App\Services\PerformerHeartService;
use App\Services\ProfileVisitService;
use App\Support\ActivitySlot;
use App\Support\FanAlias;
use App\Support\MemberDisplayName;
use App\Support\MemberProfileOptions;
use App\Support\NewBadge;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página de PERFIL de um membro, vista pela performer (feat/member-gallery-and-
 * profile, Opção B / Fase 11 click-through). É o "abrir o perfil" do card: a
 * performer vê a galeria de fotos aprovadas + o rótulo (apelido/FanAlias) para
 * saber com quem fala antes de dar coração/mensagem.
 *
 * PRIVACIDADE (não-negociável):
 *  - Só performer verificada (role:performer + can('performer-active') na rota);
 *    membro/anônimo nunca chega aqui.
 *  - O alvo é resolvido pelo HANDLE opaco contra os membros que o catálogo
 *    mostraria AGORA (a MESMA fonte da lista/ações — ResolvesCatalogMember),
 *    então o par 404/200 não vira oráculo de quem a lista esconde.
 *  - Opt-in mestre: sem `profile_visible`, NÃO há página (404 indistinguível) —
 *    o membro segue só FanAlias no catálogo, como hoje.
 *  - O payload é EXPLÍCITO (nunca serializa o User): jamais nome, e-mail, tier,
 *    saldo, gasto ou localização. Só o que o membro escolheu expor.
 *  - Abrir registra uma VISITA (Fase 13, performer→membro) RESPEITANDO o Ghost
 *    Mode de quem tem — o membro Black/privado que o ativou não recebe a visita.
 */
class MemberProfileController extends Controller
{
    public function __construct(
        private MemberCatalogService $catalog,
        private MemberGalleryService $gallery,
        private MemberGalleryAccessService $galleryAccess,
        private PerformerHeartService $hearts,
        private ProfileVisitService $visits,
        private ChatService $chatService,
    ) {}

    public function show(Request $request, string $handle): Response
    {
        $profile = $request->user()->performerProfile;

        // Só performer verificada age (como as outras portas do catálogo). 404 e
        // não 403: indistinguível das demais recusas.
        abort_unless($profile && $profile->is_verified, 404);

        // Resolve o handle contra EXATAMENTE os membros visíveis a esta performer
        // (mesma fonte da lista e das ações). Sem match → 404.
        $memberId = FanAlias::resolveHandle($profile->id, $this->catalog->visibleMemberIds(), $handle);
        abort_unless($memberId, 404);

        $member = User::where('id', $memberId)
            ->where('role', 'consumer')
            ->where('status', 'active')
            ->firstOrFail();

        // Opt-in mestre: sem perfil visível não há página. 404 indistinguível de
        // "não existe" — não confirma que o membro existe-mas-está-oculto.
        abort_unless($member->profile_visible, 404);

        // Visita bidirecional (Fase 13), RESPEITANDO o Ghost Mode do membro: quem o
        // ativou (Black/FC que optou) não deixa rastro de "quem te visitou". A
        // checagem vem ANTES de qualquer escrita; a página é a mesma com ou sem a
        // linha (Ghost Mode não é detectável de fora).
        if (! $member->hasGhostMode()) {
            $this->visits->recordPerformerVisit($profile, $member);
        }

        $hearted = in_array($member->id, $this->hearts->heartedMemberIds($profile, [$member->id]), true);

        return Inertia::render('Performer/MemberProfile', [
            // Payload EXPLÍCITO e mascarado — nada de PII/tier pega carona.
            'member' => [
                'fan_alias_label' => MemberDisplayName::for($member->nickname, $profile->id, $member->id, 'Membro #'),
                // O APELIDO isolado (ou null quando o membro não tem): a UI usa isto
                // para oferecer "denunciar apelido" (feat/nickname-report, Fase 4b-ui)
                // — só há o que denunciar quando o rótulo é um apelido escolhido, não
                // o FanAlias. É a string pública que o endpoint de denúncia resolve.
                'nickname' => MemberDisplayName::nickname($member->nickname),
                // O handle segue sendo a CHAVE das ações (coração/mensagem/visita).
                'member_handle' => FanAlias::handle($profile->id, $member->id),
                // Fotos APROVADAS da galeria, principal primeiro. Cada uma com a
                // miniatura ENQUADRADA (url) + a COMPLETA do lightbox (full_url),
                // ambas por token opaco.
                'photos' => $this->gallery->approvedFor($member, $request->user())->values(),
                // Acesso às fotos PRIVADAS (feat/member-gallery-access-requests):
                // se o membro tem privadas e qual o estado desta performer
                // ('none'|'pending'|'granted'). A UI mostra "Solicitar acesso" /
                // "Acesso solicitado" / (liberado → as privadas vêm destrancadas em
                // `photos`). Quando não há privada, `has_private` false esconde tudo.
                'private_access' => [
                    'has_private' => $this->gallery->hasPrivatePhotos($member),
                    'state' => $this->galleryAccess->stateFor($member, $request->user()),
                ],
                // Capa = avatar do membro (a foto que ELE escolheu como cara), a
                // mesma que a performer já vê no chat. A galeria vira secundária.
                // null quando o membro não tem avatar → a tela cai na 1ª foto.
                'avatar_url' => $member->avatarUrl(),
                // Faixa grossa de atividade (nunca relógio); suprimida se Invisível.
                'activity_label' => ActivitySlot::for($member->invisible_status ? null : $member->last_login_at),
                // "Online agora" (etapa 2b): booleano binário, ativo nos últimos 5
                // min e sem Status Invisível. Nunca o horário — só true/false. A tela
                // mostra "Online" quando true; quando false, cai na faixa acima.
                'is_online' => $member->isOnlineNow(),
                'is_new' => NewBadge::isNew($member->created_at),
                'hearted' => $hearted,
                // ── Perfil v2 (feat/member-profile-v2). Payload EXPLÍCITO e
                // mascarado: SÓ o que o membro preencheu/consentiu, nunca nome/
                // e-mail/tier/saldo. Campo vazio vira null/[] e a tela não o
                // renderiza (nada de "não informado").
                'bio' => $member->bio,
                // Título/headline (frase curta em itálico sob o apelido).
                'headline' => $member->headline,
                'seeking' => MemberProfileOptions::seekingLabels($member->public_seeking),
                'interests' => MemberProfileOptions::interestLabels($member->public_interests),
                // Faixa etária: opt-in, derivada do birthdate (nunca a data/idade).
                'age_band' => $member->displayAgeBand(),
                // 1ª cidade (compat) + até 3 localizações (1ª/2ª/3ª), só as preenchidas.
                'city_label' => $member->displayCityLabel(),
                'locations' => $member->displayLocations(),
                'marital_status' => MemberProfileOptions::maritalLabel($member->marital_status),
                'height' => MemberProfileOptions::heightLabel($member->height_cm),
                'weight' => MemberProfileOptions::weightLabel($member->weight_kg),
                'education' => MemberProfileOptions::educationLabel($member->education),
                'occupation_area' => MemberProfileOptions::occupationAreaLabel($member->occupation_area),
                'children' => MemberProfileOptions::childrenLabel($member->children),
                'drinks' => MemberProfileOptions::drinksLabel($member->drinks),
                'smokes' => MemberProfileOptions::smokesLabel($member->smokes),
                'availability' => MemberProfileOptions::availabilityLabel($member->availability),
                // "Membro desde" — mês/ano do created_at (nunca o dia exato).
                'member_since' => $member->created_at?->translatedFormat('M Y'),
                // Selo "Verificado" — booleano do KYC de idade, nunca dado do doc.
                'is_verified' => $member->memberIsVerified(),
            ],
            // Franquia de mensagens grátis restante hoje — a UI trava o botão.
            'messagesRemaining' => $this->chatService->remainingDailyMessages($profile),
            'messagesDailyLimit' => (int) config('member_engagement.free_messages_per_day'),
        ]);
    }
}
