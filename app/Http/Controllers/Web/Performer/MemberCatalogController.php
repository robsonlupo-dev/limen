<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Services\MemberCatalogService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogo de MEMBROS para a performer (Sprint 16). A performer navega membros
 * que optaram por aparecer e sinaliza interesse (Interesse Controlado invertido).
 *
 * Toda a regra de "quem aparece" e a máscara de privacidade vivem no
 * MemberCatalogService — o controller só delega e renderiza. Gate: grupo
 * `role:performer` (auth+2fa+documents.accepted) + `can('performer-active')` na
 * rota (só performer verificada), como as demais telas da performer.
 */
class MemberCatalogController extends Controller
{
    public function __construct(private MemberCatalogService $catalog) {}

    public function index(Request $request): Response
    {
        $profile = $request->user()->performerProfile;

        // Sem perfil não há sob qual identidade pseudonimizar o membro (o
        // FanAlias é por performer_profile_id). O gate performer-active já barra
        // quem está em KYC; esta é a defesa de borda.
        abort_unless($profile !== null, 403);

        return Inertia::render('Performer/Members', [
            'members' => $this->catalog->page($profile),
        ]);
    }
}
