<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\UpdateLifestyleTierRequest;
use App\Http\Requests\Web\UpdateMemberProfileRequest;
use App\Models\User;
use App\Support\Audit;
use App\Support\LifestyleTier;
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
 *    FanAlias em duas telas da performer: seguidores e gorjetas. NÃO no
 *    painel de visitantes — a l-diversidade que o k daquele painel não dá
 *    (revisão de 30/07); ver ProfileVisitService::revealableVisitorRows().
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
    public function edit(Request $request): Response
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
        ]);
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
