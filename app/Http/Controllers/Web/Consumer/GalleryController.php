<?php

namespace App\Http\Controllers\Web\Consumer;

use App\Exceptions\CsamDetectedException;
use App\Exceptions\ImageProcessingException;
use App\Exceptions\MemberGalleryException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UploadMediaRequest;
use App\Models\MemberGalleryPhoto;
use App\Services\MemberGalleryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Galeria de fotos do MEMBRO — lado do dono (feat/member-gallery-and-profile).
 * Subir (até 4), remover, designar principal e ligar/desligar o perfil visível.
 * Toda a regra vive no MemberGalleryService; aqui traduzimos as recusas do
 * pipeline (imagem-bomba/CSAM/limite) para erro de formulário 422, nunca 500 —
 * mesma disciplina do avatar (Consumer\ProfileController::avatar).
 *
 * A tela é a MESMA de `consumer.profile.edit` (ProfileController::edit já injeta
 * `gallery` e `profile_visible`); estas rotas só mutam.
 */
class GalleryController extends Controller
{
    public function store(UploadMediaRequest $request, MemberGalleryService $gallery): RedirectResponse
    {
        try {
            $gallery->add($request->user(), $request->file('file'), $request);
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
