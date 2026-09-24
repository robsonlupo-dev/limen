<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Exceptions\CsamDetectedException;
use App\Exceptions\ImageProcessingException;
use App\Exceptions\MemberGalleryException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\UploadGalleryPhotoRequest;
use App\Models\MemberGalleryPhoto;
use App\Services\MemberGalleryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Galeria de fotos do MEMBRO — lado do dono (feat/member-gallery-and-profile).
 * Subir (até MAX_ACTIVE), remover, designar principal, trancar/destrancar por foto
 * (feat/member-gallery-per-photo-privacy) e ligar/desligar o perfil visível.
 * Toda a regra vive no MemberGalleryService; aqui traduzimos as recusas do
 * pipeline (imagem-bomba/CSAM/limite) para erro de formulário 422, nunca 500 —
 * mesma disciplina do avatar (Consumer\ProfileController::avatar).
 *
 * A tela é a MESMA de `consumer.profile.edit` (ProfileController::edit já injeta
 * `gallery` e `profile_visible`); estas rotas só mutam.
 */
class GalleryController extends Controller
{
    public function store(UploadGalleryPhotoRequest $request, MemberGalleryService $gallery): RedirectResponse
    {
        try {
            $gallery->add($request->user(), $request->file('file'), $request->file('cropped'), $request);
        } catch (MemberGalleryException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        } catch (ImageProcessingException|CsamDetectedException $e) {
            // Mensagem genérica de propósito (o CSAM não confirma o motivo; a
            // conta já foi sinalizada dentro do service).
            return back()->withErrors(['file' => 'Não foi possível processar esta imagem. Tente outra foto.']);
        }

        return back()->with('success', 'Foto enviada. Ela passa por análise antes de aparecer no seu perfil.');
    }

    public function destroy(Request $request, MemberGalleryPhoto $photo, MemberGalleryService $gallery): RedirectResponse
    {
        $gallery->remove($request->user(), $photo, $request);

        return back()->with('success', 'Foto removida.');
    }

    public function primary(Request $request, MemberGalleryPhoto $photo, MemberGalleryService $gallery): RedirectResponse
    {
        $gallery->setPrimary($request->user(), $photo, $request);

        return back()->with('success', 'Foto principal definida.');
    }

    /**
     * Tranca/destranca UMA foto (aberta ↔ privada) —
     * feat/member-gallery-per-photo-privacy. Booleano do payload, aplicado por
     * forceFill no service (`is_private` fora do $fillable). Foto de outro / recusada
     * → 404 no service.
     */
    public function photoVisibility(Request $request, MemberGalleryPhoto $photo, MemberGalleryService $gallery): RedirectResponse
    {
        $validated = $request->validate(['is_private' => ['required', 'boolean']]);

        $gallery->setPhotoVisibility($request->user(), $photo, $validated['is_private'], $request);

        return back()->with('success', $validated['is_private']
            ? 'Foto trancada. Só você vê — até liberar para alguém.'
            : 'Foto aberta. As performers que veem seu perfil agora veem esta foto.');
    }

    /**
     * Liga/desliga o PERFIL VISÍVEL (opt-in mestre). Booleano do payload,
     * aplicado por forceFill no service (`profile_visible` fora do $fillable).
     */
    public function visibility(Request $request, MemberGalleryService $gallery): RedirectResponse
    {
        $validated = $request->validate(['profile_visible' => ['required', 'boolean']]);

        $gallery->setVisibility($request->user(), $validated['profile_visible'], $request);

        return back()->with('success', $validated['profile_visible']
            ? 'Seu perfil está visível para as performers.'
            : 'Seu perfil voltou a ficar oculto.');
    }
}
