<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Exceptions\CsamDetectedException;
use App\Exceptions\ImageProcessingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UploadMediaRequest;
use App\Http\Requests\Web\UpdateLifestyleTierRequest;
use App\Http\Requests\Web\UpdateMemberProfileRequest;
use App\Http\Requests\Web\UpdateMemberPublicProfileRequest;
use App\Exceptions\NicknameException;
use App\Models\User;
use App\Services\MemberAvatarService;
use App\Services\MemberGalleryService;
use App\Services\MemberNicknameService;
use App\Support\AgeBand;
use App\Support\Audit;
use App\Support\LifestyleTier;
use App\Support\MemberProfileOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Perfil do MEMBRO (Sprint 9): interesses + "o que estou buscando".
 *
 * Tela separada de `consumer.settings`, que é privacidade e preferências de
 * conta (Modo Discreto, perks, encerramento). Aqui é auto-declaração — e a
 * separação não é só arrumação: a tela de configurações é o lugar onde o membro
 * ESCONDE coisas, e misturar nela um formulário que coleta gosto pessoal manda
 * o sinal errado sobre o que acontece com o dado.
 *
 * PRIVACIDADE — a tela tem DUAS metades com destinos opostos, e confundi-las é
 * o erro caro aqui:
 *
 *  - `interests` e `seeking` NUNCA voltam para uma superfície da performer. Nem
 *    o valor, nem contagem, nem "vocês têm 3 interesses em comum". Existem para
 *    o cruzamento de afinidade do Sprint 10 (que roda no servidor e devolve
 *    ORDEM, não o insumo) e para filtros do catálogo, que é o membro filtrando
 *    performer — a direção segura. Ver o cabeçalho de App\Models\MemberInterest
 *    para o porquê inteiro, e MemberInterestsTest para o teste que trava isso.
 *
 *  - `lifestyle_tier` (Sprint 10) VOLTA, por decisão do PO: sai ao lado do
 *    FanAlias nas três telas da performer (seguidores, gorjetas, visitantes).
 *    Por isso ele entra por endpoint PRÓPRIO (update() não o toca), fica fora
 *    do `$fillable`, e a tela avisa quem vê ANTES do preenchimento — não nos
 *    Termos. A ressalva de correlação cross-perfil está em
 *    App\Support\LifestyleTier; leia-a antes de ampliar a exibição.
 *
 * A copy da tela é dividida na mesma linha, e isso não é detalhe de layout: um
 * "isto é só seu" cobrindo a seção errada seria promessa falsa sobre o único
 * campo que a performer lê.
 */
class ProfileController extends Controller
{
    public function edit(Request $request, MemberGalleryService $gallery): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Consumer/Profile/Edit', [
            'profile' => [
                // Sai da junção como lista de slugs — a tela marca os chips por
                // slug, e o rótulo é dela (resources/js/lib/performerAttributes).
                'interests' => $user->interestSlugs(),
                'seeking' => $user->seeking,
                // `null` na coluna vira o slug de opt-out para a tela: o radio
                // precisa de alguém marcado, e "Prefiro não dizer" é o padrão.
                // A volta é normalizada de novo no request — a tela não é a
                // dona da equivalência.
                'lifestyle_tier' => LifestyleTier::forForm($user->lifestyle_tier),
            ],
            // Rótulos e descrições vêm do servidor, não de uma tabela no Vue:
            // o mesmo texto é lido pelo formulário do membro e pelo painel da
            // performer, e duas listas divergiriam justo no lado que ele não vê.
            'lifestyleOptions' => LifestyleTier::options(),
            // Foto de perfil (fix/member-photo-and-crop). URL assinada montada
            // pelo model (nunca o caminho/token crus); null quando não subiu foto,
            // e a tela cai na silhueta. É a PRÓPRIA foto do membro — o member_id
            // não vaza para si mesmo.
            'avatar_url' => $user->avatarUrl(),
            // Apelido (feat/member-nickname): o valor atual (null = sem apelido, a
            // performer vê o FanAlias) + quando a próxima troca fica liberada
            // (cooldown de 7 dias). O relógio (`nickname_set_at`) é $hidden — a tela
            // recebe só a data-alvo já calculada, nunca o carimbo cru.
            'nickname' => $user->nickname,
            'nickname_change_available_at' => $user->nickname_set_at
                ? $user->nickname_set_at->copy()->addDays((int) config('nickname.cooldown_days'))->toIso8601String()
                : null,
            // Galeria de perfil (feat/member-gallery-and-profile). TODAS as fotos do
            // dono (qualquer status), com a URL de preview quando há bytes — a
            // recusada some do disco e vem só com o motivo. `profile_visible` é o
            // opt-in mestre (default OFF); `gallery_max` deixa a tela travar o botão.
            'gallery' => $gallery->forOwner($user),
            'profile_visible' => (bool) $user->profile_visible,
            'gallery_max' => \App\Models\MemberGalleryPhoto::MAX_ACTIVE,

            // Perfil PÚBLICO v2 (feat/member-profile-v2). Ao contrário de
            // interests/seeking acima, TUDO aqui VOLTA para a performer quando o
            // perfil está visível — por isso a tela avisa "isto a performer vê"
            // e a copy de "só seu" NÃO cobre esta seção. Valores atuais + as
            // listas controladas (rótulos do servidor, nunca duplicados no Vue).
            'public_profile' => [
                'bio' => $user->bio,
                'headline' => $user->headline,
                'public_seeking' => $user->public_seeking ?? [],
                'public_interests' => $user->public_interests ?? [],
                'profile_city' => $user->profile_city,
                'profile_uf' => $user->profile_uf,
                'profile_city_2' => $user->profile_city_2,
                'profile_uf_2' => $user->profile_uf_2,
                'profile_city_3' => $user->profile_city_3,
                'profile_uf_3' => $user->profile_uf_3,
                'marital_status' => $user->marital_status,
                'height_cm' => $user->height_cm,
                'weight_kg' => $user->weight_kg,
                'education' => $user->education,
                'occupation_area' => $user->occupation_area,
                'children' => $user->children,
                'drinks' => $user->drinks,
                'smokes' => $user->smokes,
                'availability' => $user->availability,
                'show_age_band' => (bool) $user->show_age_band,
                // Derivados, para o PREVIEW de "como a performer vê": a faixa (do
                // birthdate), o selo (do KYC) e "membro desde". A faixa vem SEMPRE
                // (a tela mostra no preview só se o toggle estiver ligado); nunca
                // a data/idade exata.
                'age_band' => AgeBand::for($user->birthdate),
                'is_verified' => $user->memberIsVerified(),
                'member_since' => $user->created_at?->translatedFormat('M Y'),
            ],
            'publicProfileOptions' => [
                'seeking' => MemberProfileOptions::seekingOptions(),
                'interests' => MemberProfileOptions::interestOptions(),
                'marital' => MemberProfileOptions::maritalOptions(),
                'heights' => MemberProfileOptions::heightOptions(),
                'weights' => MemberProfileOptions::weightOptions(),
                'education' => MemberProfileOptions::educationOptions(),
                'occupation_area' => MemberProfileOptions::occupationAreaOptions(),
                'children' => MemberProfileOptions::childrenOptions(),
                'drinks' => MemberProfileOptions::drinksOptions(),
                'smokes' => MemberProfileOptions::smokesOptions(),
                'availability' => MemberProfileOptions::availabilityOptions(),
                'max_seeking' => MemberProfileOptions::MAX_SEEKING,
                'max_interests' => MemberProfileOptions::MAX_INTERESTS,
            ],
        ]);
    }

    /**
     * Salva o perfil PÚBLICO v2 (bio, "o que busco"/interesses públicos, cidade/
     * UF, estado civil, altura, opt-in da faixa etária). Endpoint PRÓPRIO — como
     * o de estilo de vida e o de apelido — porque estes campos VOLTAM para a
     * performer: ficam fora do $fillable e entram por forceFill de allowlist, com
     * o UpdateMemberPublicProfileRequest como fronteira de confiança.
     *
     * array_key_exists e não isset: a tela pode mandar '' / [] para LIMPAR um
     * campo, e ausente é "não mexe". Confundir os dois recusaria a única operação
     * (apagar o que já está exposto) que o titular não refaz por outro caminho.
     */
    public function updatePublicProfile(UpdateMemberPublicProfileRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validated();
        $changes = [];

        // Texto/escalares: '' vira null (uma representação só para "vazio").
        $scalarFields = [
            'bio', 'headline',
            'profile_city', 'profile_uf',
            'profile_city_2', 'profile_uf_2',
            'profile_city_3', 'profile_uf_3',
            'marital_status', 'education', 'occupation_area',
            'children', 'drinks', 'smokes', 'availability',
        ];
        foreach ($scalarFields as $field) {
            if (array_key_exists($field, $validated)) {
                $value = trim((string) ($validated[$field] ?? ''));
                $changes[$field] = $value === '' ? null : $value;
            }
        }

        // Altura e peso: inteiro ou null.
        foreach (['height_cm', 'weight_kg'] as $field) {
            if (array_key_exists($field, $validated)) {
                $changes[$field] = $validated[$field] !== null ? (int) $validated[$field] : null;
            }
        }

        // Arrays de slugs: [] vira null (vazio tem uma representação só).
        foreach (['public_seeking', 'public_interests'] as $field) {
            if (array_key_exists($field, $validated)) {
                $slugs = array_values(array_unique($validated[$field] ?? []));
                $changes[$field] = $slugs === [] ? null : $slugs;
            }
        }

        if (array_key_exists('show_age_band', $validated)) {
            $changes['show_age_band'] = (bool) $validated['show_age_band'];
        }

        if ($changes !== []) {
            // forceFill: os campos estão fora do $fillable de propósito (a
            // performer os lê). O valor já veio validado contra as allowlists.
            $user->forceFill($changes)->save();
        }

        // Audit sem CONTEÚDO — só quais campos mudaram. Bio é texto pessoal e as
        // tags são preferência declarada; gravar o valor faria do audit_logs uma
        // segunda cópia fora do alcance do scrub do Hard Delete (mesma disciplina
        // do member_profile_updated).
        Audit::log('member_public_profile_updated', $user, [
            'fields' => array_keys($changes),
        ], $request);

        return back()->with('success', 'Perfil atualizado.');
    }

    /**
     * Define/troca o apelido (feat/member-nickname). Toda a regra (validação
     * rígida, unicidade, cooldown) vive no MemberNicknameService; aqui só
     * traduzimos a recusa para um erro de formulário no campo `nickname`.
     */
    public function updateNickname(Request $request, MemberNicknameService $nicknames): RedirectResponse
    {
        $validated = $request->validate(['nickname' => ['required', 'string', 'max:20']]);

        try {
            $nicknames->set($request->user(), $validated['nickname'], $request);
        } catch (NicknameException $e) {
            return back()->withErrors(['nickname' => $e->getMessage()]);
        }

        return back()->with('success', 'Apelido salvo.');
    }

    /** Remove o apelido: o membro volta ao FanAlias na exibição. */
    public function deleteNickname(Request $request, MemberNicknameService $nicknames): RedirectResponse
    {
        $nicknames->remove($request->user(), byModerator: false, request: $request);

        return back()->with('success', 'Apelido removido.');
    }

    /**
     * Adiciona/troca a foto de perfil do membro. Reusa o pipeline da performer
     * via MemberAvatarService (sanitização + anti-CSAM); UploadMediaRequest é a
     * MESMA validação (5 MB, jpeg/png/webp) do avatar/capa da performer.
     */
    public function avatar(UploadMediaRequest $request, MemberAvatarService $avatars): RedirectResponse
    {
        // Mesma disciplina dos 10 outros caminhos de imagem: a exceção do
        // pipeline (imagem-bomba/corrompida, ou match anti-CSAM) volta como erro
        // de validação 422, não 500. A mensagem do CSAM é genérica de propósito
        // (não confirma o motivo); a conta já foi sinalizada dentro do service.
        try {
            $avatars->replace($request->user(), $request->file('file'), $request);
        } catch (ImageProcessingException|CsamDetectedException $e) {
            return back()->withErrors(['file' => 'Não foi possível processar esta imagem. Tente outra foto.']);
        }

        return back()->with('success', 'Foto de perfil atualizada.');
    }

    /** Remove a foto de perfil do membro (idempotente). */
    public function deleteAvatar(Request $request, MemberAvatarService $avatars): RedirectResponse
    {
        $avatars->remove($request->user(), $request);

        return back()->with('success', 'Foto de perfil removida.');
    }

    public function update(UpdateMemberProfileRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validated();

        // `array_key_exists` e não `! empty`/`isset`: a tela manda `seeking:
        // ''` e `interests: []` quando o membro limpa os campos, e isso precisa
        // apagar o que estava lá. Ausente é "não mexe" — presente-e-vazio é
        // limpeza deliberada, e confundir os dois faria o formulário recusar
        // silenciosamente a única operação que o titular não consegue refazer
        // por outro caminho.
        if (array_key_exists('seeking', $validated)) {
            // '' → null: a coluna é nullable e "não preenchido" tem uma
            // representação só. Duas ('' e null) fariam o cruzamento do Sprint
            // 10 precisar tratar as duas, e uma delas seria esquecida.
            $seeking = trim((string) ($validated['seeking'] ?? ''));
            $user->fill(['seeking' => $seeking === '' ? null : $seeking])->save();
        }

        $syncedInterests = array_key_exists('interests', $validated);

        if ($syncedInterests) {
            $user->syncInterests($validated['interests'] ?? []);
        }

        // Audit sem o CONTEÚDO — só quais campos mudaram. `seeking` é texto
        // livre sobre desejo pessoal e os interesses são dado sensível de vida
        // sexual (LGPD art. 5º, II); gravar o valor faria do audit_logs uma
        // segunda cópia fora do alcance do scrub do Hard Delete, que é a mesma
        // razão pela qual o filtro do chat nunca registra o corpo da mensagem.
        Audit::log('member_profile_updated', $user, [
            'fields' => array_keys($validated),
        ], $request);

        return back()->with('success', 'Perfil atualizado.');
    }

    /**
     * "Estilo de Vida" — endpoint dedicado, porque o campo está fora do
     * `$fillable` (ver UpdateLifestyleTierRequest).
     *
     * Escreve com `forceFill` e não `update`: o ponto de tirar a coluna do
     * `$fillable` é que ela nunca entre por payload genérico, e é AQUI — no
     * único chamador, com o valor já validado contra a allowlist e normalizado
     * — que a exceção fica visível.
     */
    public function updateLifestyleTier(UpdateLifestyleTierRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->forceFill(['lifestyle_tier' => $request->tier()])->save();

        // Audit SEM o valor — só se passou a haver faixa exibida ou não.
        //
        // A primeira versão gravava o slug, e estava errada: `audit_logs` é a
        // única tabela que o DeletionService PRESERVA INTACTA (§ 3 do cabeçalho
        // dele), inclusive com o IP em claro. Gravar "patrono" ali fazia o scrub
        // de `lifestyle_tier` no Hard Delete ser cosmético — a linha encerrada
        // continuaria carregando o retrato patrimonial que o scrub existe para
        // tirar, ao lado do IP de quem pediu para sumir. E uma linha por
        // alteração, em ordem, É a trajetória declarada do membro: o histórico
        // que eu tinha afirmado no comentário não estar guardando.
        //
        // O booleano responde a pergunta que o audit precisa responder ("desde
        // quando havia faixa exibida na tela dela, e por decisão de quem") sem
        // guardar QUAL. É o mesmo corte do filtro de chat, que grava categoria e
        // `rule_hash` e nunca o corpo, e do member_profile_updated logo acima,
        // que grava os nomes dos campos e nunca o conteúdo.
        //
        // Sempre presente e sempre booleano: um campo ausente lido como "não
        // mexeu" é a ambiguidade que a trilha existe para não ter.
        Audit::log('member_lifestyle_tier_updated', $user, [
            'disclosed' => $request->tier() !== null,
        ], $request);

        return back()->with('success', 'Estilo de vida atualizado.');
    }
}
