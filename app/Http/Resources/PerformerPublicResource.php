<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

class PerformerPublicResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'stage_name' => $this->stage_name,
            'bio' => $this->bio,
            'category' => $this->category,
            // Lista completa de mundos. `category` continua sendo o principal
            // (ordenação, ícone, rótulo da tela); `worlds` é para quem precisa
            // dos quatro — performer legado cai em [category] pelo
            // activeWorlds(), então nunca chega vazio ao front.
            'worlds' => $this->activeWorlds(),
            'work_modes' => $this->work_modes,
            // "Sobre mim" (Sprint 9) — auto-declarado pela performer e exibido no
            // perfil público. Nada aqui é coletado pela plataforma nem cruza com
            // o KYC: são os mesmos campos que ela preenche na tela de edição.
            // `tags` sai como lista de slugs; o rótulo é do frontend.
            'tags' => $this->tagSlugs(),
            'languages' => $this->languages ?? [],
            'drinks' => $this->drinks,
            'smokes' => $this->smokes,
            'height_cm' => $this->height_cm,
            'looking_for' => $this->looking_for,
            'is_live' => $this->is_live,
            'rating_avg' => $this->rating_avg,
            'rating_count' => $this->rating_count,
            // Faixa, nunca o número exato: ver PerformerProfile::followersCountLabel().
            'followers_label' => $this->followersCountLabel(),
            // Selos de verificação — BOOLEANOS, nunca a data. O timestamp de
            // `email_verified_at` dataria o cadastro da performer para qualquer
            // visitante anônimo, e o badge não precisa dele para renderizar.
            'is_verified' => (bool) $this->is_verified,
            'email_verified' => $this->user?->email_verified_at !== null,
            'avatar_url' => $this->mediaUrl('avatar'),
            'cover_url' => $this->mediaUrl('cover'),
        ];
    }

    protected function mediaUrl(string $type): ?string
    {
        $path = $type === 'avatar' ? $this->avatar_path : $this->cover_path;

        if (! $path) {
            return null;
        }

        // Use profile id (not user_id) to avoid exposing internal user identifiers.
        return URL::temporarySignedRoute(
            'performer.media',
            now()->addMinutes(60),
            ['profile_id' => $this->id, 'type' => $type]
        );
    }
}
