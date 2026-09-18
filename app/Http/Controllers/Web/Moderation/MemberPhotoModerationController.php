<?php

namespace App\Http\Controllers\Web\Moderation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Moderation\ModerateMemberPhotoRequest;
use App\Models\MemberGalleryPhoto;
use App\Services\MemberGalleryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fila de moderação das fotos de galeria de membro
 * (feat/member-gallery-and-profile). Sob `/moderacao/*` (`auth` +
 * `moderator.access` — moderador OU admin), a mesma porta da fila de denúncias e
 * da intro de voz.
 *
 * Moderação PRÉ-publicação: a foto só vai ao ar (perfil/catálogo) depois do
 * `approved`. Motivo (feature sensível — rosto de usuário em site adulto): CSAM,
 * foto de TERCEIRO sem consentimento, imagem não-consentida. O anti-CSAM automático
 * já rodou no upload; o humano é o gate contra o resto. O moderador VÊ os bytes
 * por endpoint dedicado e throttlado (nunca na prop da página).
 *
 * Referência do membro: só o id interno (`Membro #N`) — a equipe de moderação é
 * confiável e já lida com PII na fila de denúncias/KYC; ainda assim não expomos
 * nome/e-mail aqui, só o necessário para agir.
 */
class MemberPhotoModerationController extends Controller
{
    public function __construct(private MemberGalleryService $gallery) {}

    /** A fila de pendentes, na ordem de chegada, com o thumbnail. */
    public function index(): Response
    {
        $pending = MemberGalleryPhoto::query()
            ->pending()
            ->orderBy('id')
            ->paginate(50)
            ->through(fn (MemberGalleryPhoto $photo) => [
                'id' => $photo->id,
                'member_ref' => 'Membro #'.$photo->user_id,
                'created_at' => $photo->created_at,
                // O moderador VÊ a imagem por este endpoint dedicado (throttlado);
                // os bytes nunca entram na prop da página.
                'image_url' => route('moderacao.member-photos.image', $photo->id),
            ]);

        return Inertia::render('Moderacao/MemberPhotos/Index', [
            'photos' => $pending,
            'pendingCount' => MemberGalleryPhoto::pending()->count(),
        ]);
    }

    /** Aprova ou recusa (com motivo). A mutação passa SÓ pelo serviço. */
    public function update(ModerateMemberPhotoRequest $request, MemberGalleryPhoto $photo): RedirectResponse
    {
        $validated = $request->validated();

        if ($validated['status'] === MemberGalleryPhoto::STATUS_APPROVED) {
            $this->gallery->approve($photo, $request->user());
            $flash = "Foto #{$photo->id} aprovada.";
        } else {
            $this->gallery->reject($photo, $request->user(), $validated['reject_reason'] ?? null);
            $flash = "Foto #{$photo->id} recusada.";
        }

        return redirect()
            ->route('moderacao.member-photos.index')
            ->with('success', $flash);
    }

    /**
     * Serve os bytes para o moderador VER na fila. Content-Type FIXO: nós
     * produzimos o JPEG (re-encode no pipeline). Só há bytes enquanto pending —
     * a recusa os purga; 404 depois disso.
     */
    public function image(MemberGalleryPhoto $photo): StreamedResponse
    {
        abort_if($photo->path === '' || ! Storage::disk(MemberGalleryService::DISK)->exists($photo->path), 404);

        return Storage::disk(MemberGalleryService::DISK)->response($photo->path, null, [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
