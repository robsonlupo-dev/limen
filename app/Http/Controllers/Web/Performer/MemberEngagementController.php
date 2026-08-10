<?php

namespace App\Http\Controllers\Web\Performer;

use App\Exceptions\ChatException;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecordMemberVisitRequest;
use App\Http\Requests\SendCatalogMessageRequest;
use App\Http\Requests\SendHeartRequest;
use App\Services\ChatService;
use App\Services\PerformerHeartService;
use App\Services\ProfileVisitService;
use Illuminate\Http\JsonResponse;

/**
 * Motor de engajamento do catálogo de membros (home da performer): CORAÇÃO
 * (grátis, ilimitado) e MENSAGEM PERSONALIZADA (franquia diária grátis; o membro
 * paga para LER). As duas ações resolvem o alvo contra os membros visíveis à
 * performer (ResolvesCatalogMember) — a lista e a ação concordam por construção.
 *
 * Toda a regra vive nos serviços (PerformerHeartService, ChatService); o
 * controller delega. Gate na rota: `role:performer` + `can('performer-active')`,
 * como as outras portas do catálogo.
 */
class MemberEngagementController extends Controller
{
    public function __construct(
        private PerformerHeartService $hearts,
        private ChatService $chatService,
        private ProfileVisitService $visits,
    ) {}

    /**
     * Registra a visita da performer ao perfil de um membro (visitas
     * bidirecionais, A.0.4). Disparado quando a performer ABRE o perfil de um
     * membro no catálogo — o membro depois vê "quem visitou seu perfil".
     *
     * Corpo estável e sempre 204: gravou ou caiu na dedup de 30min, a resposta é
     * a mesma. A performer não decide nada com o resultado, e a página não muda
     * conforme a visita foi ou não gravada — mesma disciplina de record().
     */
    public function visit(RecordMemberVisitRequest $request): JsonResponse
    {
        $member = $request->resolvedMember();
        $profile = $request->user()->performerProfile;

        $this->visits->recordPerformerVisit($profile, $member);

        return response()->json([], 204);
    }

    public function heart(SendHeartRequest $request): JsonResponse
    {
        // resolvedMember() aborta 404 se não há perfil verificado — então depois
        // dele o perfil é garantido.
        $member = $request->resolvedMember();
        $profile = $request->user()->performerProfile;

        $this->hearts->heart($profile, $member);

        // Corpo estável: curtir e recurtir respondem igual (idempotente).
        return response()->json(['hearted' => true], 201);
    }

    public function message(SendCatalogMessageRequest $request): JsonResponse
    {
        $member = $request->resolvedMember();
        $profile = $request->user()->performerProfile;

        try {
            $this->chatService->sendCatalogMessage($profile, $member, $request->validated('body'));
        } catch (ChatException $e) {
            // Inclui a franquia esgotada (daily_message_limit): a UI mostra
            // "você usou suas N mensagens grátis de hoje".
            return response()->json([
                'reason' => $e->reason,
                'message' => $e->getMessage(),
                'messages_remaining_today' => $this->chatService->remainingDailyMessages($profile),
            ], 422);
        }

        return response()->json([
            'sent' => true,
            'messages_remaining_today' => $this->chatService->remainingDailyMessages($profile),
        ], 201);
    }
}
