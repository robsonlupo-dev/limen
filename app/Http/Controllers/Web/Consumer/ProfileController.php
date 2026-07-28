<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\UpdateMemberProfileRequest;
use App\Models\User;
use App\Support\Audit;
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
 * PRIVACIDADE — o que estas duas ações escrevem NUNCA volta para uma superfície
 * da performer. Nem `interests`, nem `seeking`, nem contagem, nem "vocês têm 3
 * interesses em comum". Os campos existem para o cruzamento de afinidade do
 * Sprint 10 (que roda no servidor e devolve ORDEM, não o insumo) e para filtros
 * do catálogo, que é o membro filtrando performer — a direção segura. Ver o
 * cabeçalho de App\Models\MemberInterest para o porquê inteiro, e
 * MemberInterestsTest para o teste que trava isso.
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
            ],
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
}
