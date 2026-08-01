<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\DeleteMemberNoteRequest;
use App\Http\Requests\Web\SaveMemberNoteRequest;
use App\Models\MemberNote;
use App\Services\MemberNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Notas privadas da performer sobre membros (Sprint 11).
 *
 * A performer anota o que quiser sobre um FanAlias; o membro NUNCA vê. Toda a
 * regra (incl. o que jamais pode responder pelo lado do membro) mora em
 * MemberNoteService, e a resolução `member_handle` → membro mora no trait dos
 * Form Requests — este controller só orquestra e devolve o payload já
 * pseudonimizado (`present()`).
 *
 * O id cru do membro nunca sai daqui: index e as respostas de PUT usam
 * `FanAlias` via `MemberNoteService::present()`; o handle da rota de PUT/DELETE
 * é resolvido de volta pelo trait.
 *
 * Gates (ver routes/web.php): `auth`+`2fa`+`documents.accepted` dos grupos-pai,
 * mais `role:performer` e `can('performer-active')` EXPLÍCITOS em cada rota — o
 * grupo `documents.accepted` não aplica `role:performer` no todo. O
 * `Gate::authorize('performer-active')` aqui é a terceira camada (defesa em
 * profundidade), não a única.
 */
class MemberNotesController extends Controller
{
    public function __construct(private MemberNoteService $notes) {}

    /** Lista completa das notas da performer (com busca client-side na tela). */
    public function index(Request $request): Response
    {
        Gate::authorize('performer-active');

        $profile = $request->user()->performerProfile;

        $notes = $this->notes->paginateFor($profile);

        return Inertia::render('Performer/Notes', [
            'notes' => $notes->through(fn (MemberNote $note) => $this->notes->present($note)),
        ]);
    }

    /**
     * Cria ou atualiza a nota sobre o membro resolvido do handle.
     *
     * Resposta JSON (o front consome com `putJson`): devolve o payload
     * pseudonimizado da nota, para o modal refletir o estado salvo sem recarregar.
     */
    public function update(SaveMemberNoteRequest $request): JsonResponse
    {
        Gate::authorize('performer-active');

        $profile = $request->user()->performerProfile;
        $member = $request->resolvedMember();

        $note = $this->notes->upsert($profile, $member, (string) $request->validated('content'));

        return response()->json([
            'saved' => true,
            'note' => $this->notes->present($note),
        ]);
    }

    /**
     * Apaga a nota sobre o membro resolvido do handle. Idempotente: some se
     * existia, no-op se não — resposta uniforme nos dois casos.
     */
    public function destroy(DeleteMemberNoteRequest $request): JsonResponse
    {
        Gate::authorize('performer-active');

        $profile = $request->user()->performerProfile;
        $member = $request->resolvedMember();

        $this->notes->delete($profile, $member);

        return response()->json(['deleted' => true]);
    }
}
