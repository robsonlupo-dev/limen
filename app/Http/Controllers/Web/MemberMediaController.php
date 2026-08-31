<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serving da foto de perfil do MEMBRO (fix/member-photo-and-crop). Gêmeo do
 * PerformerMediaController: disco privado, rota ASSINADA (bearer curto, sem
 * sessão), bytes servidos pela camada de controller — nunca URL pública do disco.
 *
 * Chaveado pelo `avatar_token` OPACO, NUNCA pelo user_id: é o que impede a foto
 * servida à performer de vazar o member_id que o FanAlias esconde. Token
 * desconhecido/foto ausente → 404 (indistinguível), sem oráculo de existência.
 */
class MemberMediaController extends Controller
{
    public function __invoke(Request $request): StreamedResponse|Response
    {
        $token = (string) $request->input('token');

        abort_if($token === '', 404);

        $user = User::where('avatar_token', $token)->first();

        abort_if($user === null || ! $user->avatar_path
            || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($user->avatar_path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($user->avatar_path);
    }
}
