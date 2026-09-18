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
 * O GATE de "quem vê" (approved + dono com profile_visible) é aplicado na
 * GERAÇÃO da URL (MemberGalleryService só entrega URL de aprovada à performer; o
 * dono recebe URL da própria em qualquer status). Aqui só servimos os bytes se o
 * arquivo existir — removida/recusada some do disco e a URL 404 na hora
 * (revogação imediata), como o avatar.
 */
class MemberGalleryMediaController extends Controller
{
    public function __invoke(Request $request): StreamedResponse|Response
    {
        $token = (string) $request->input('token');

        abort_if($token === '', 404);

        $photo = MemberGalleryPhoto::where('token', $token)->first();

        abort_if($photo === null || $photo->path === ''
            || ! Storage::disk(MemberGalleryService::DISK)->exists($photo->path), 404);

        return Storage::disk(MemberGalleryService::DISK)->response($photo->path);
    }
}
