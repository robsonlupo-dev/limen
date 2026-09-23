<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\MemberGalleryPhoto;
use App\Services\MemberGalleryService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serving das fotos da galeria do MEMBRO (feat/member-gallery-and-profile). Gêmeo
 * do MemberMediaController do avatar: disco privado, rota ASSINADA (bearer curto,
 * sem sessão), bytes servidos pela camada de controller — nunca URL pública.
 *
 * Chaveado pelo `token` OPACO da foto, NUNCA pelo id/user_id: é o que impede a
 * foto servida à performer de vazar o member_id que o FanAlias esconde. Token
 * desconhecido/arquivo ausente → 404 (indistinguível), sem oráculo de existência.
 *
 * O GATE de "quem vê" é reconferido AQUI (defense-in-depth, não só na geração da
 * URL):
 *  - o DONO (sessão autenticada) vê a PRÓPRIA em qualquer status — é o preview da
 *    tela de gestão (pending/aprovada);
 *  - qualquer outro (performer com a URL assinada, ou uma URL vazada) só recebe
 *    bytes se a foto está APROVADA, o dono com o perfil visível, E a foto é
 *    PÚBLICA (feat/member-gallery-per-photo-privacy). Assim, desligar
 *    `profile_visible` OU trancar a foto REVOGA na hora até as URLs já emitidas
 *    (não espera a assinatura de 60min expirar), e uma pending/recusada/privada
 *    nunca vaza. O predicado mora no MemberGalleryService::canServeToViewer.
 *
 * Removida/recusada some do disco → 404 na hora (revogação imediata dos bytes),
 * como o avatar. Token desconhecido → 404 indistinguível, sem oráculo.
 */
class MemberGalleryMediaController extends Controller
{
    public function __construct(private MemberGalleryService $gallery) {}

    public function __invoke(Request $request): StreamedResponse|Response
    {
        $token = (string) $request->input('token');

        abort_if($token === '', 404);

        // Duas variantes, um mesmo endpoint: o `token` serve a ENQUADRADA (`path`,
        // card/miniatura), o `full_token` serve a COMPLETA (`full_path`, lightbox).
        // Resolve por qualquer um dos dois tokens OPACOS e serve o arquivo daquela
        // variante — nunca vaza id/user_id em nenhum dos casos.
        $photo = MemberGalleryPhoto::where('token', $token)
            ->orWhere('full_token', $token)
            ->first();

        abort_if($photo === null, 404);

        $path = $photo->full_token === $token ? $photo->full_path : $photo->path;

        abort_if($path === '' || $path === null
            || ! Storage::disk(MemberGalleryService::DISK)->exists($path), 404);

        // Gate ÚNICO (feat/member-gallery-per-photo-privacy): o dono vê a própria em
        // qualquer status; qualquer outro só recebe bytes de foto APROVADA, de dono
        // com perfil visível, e PÚBLICA. O MESMO predicado que o presenter usa para
        // decidir url-vs-cadeado — as duas pontas não podem divergir (sem oráculo).
        abort_unless($this->gallery->canServeToViewer($photo, $request->user()), 404);

        return Storage::disk(MemberGalleryService::DISK)->response($path);
    }
}
