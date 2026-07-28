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
            // Localização opt-in — SÓ a UF, e null para quem não preencheu.
            //
            // **`city` NÃO entra aqui, e não é esquecimento.** A cidade é dado
            // interno: guardada porque a performer a digitou, nunca publicada.
            // Estado é grosso o bastante (27 unidades) para não localizar
            // ninguém; município nomeia um lugar, e num produto onde a
            // performer depende de não ser encontrada isso é outra categoria de
            // dado. Esta é a única porta pelos dois catálogos e pelo perfil
            // público — acrescentar `city` aqui vaza em todas de uma vez.
            // Travado por teste (LocationTest).
            'state' => $this->state,
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
            // Curadoria concedida por admin (verificada/select/maison), ou null
            // para quem ainda não recebeu nenhuma. Sai o SLUG, nunca o rótulo —
            // a tela traduz, mesma regra dos enums de "Sobre mim".
            //
            // Só o tier: `tier_granted_at` e `tier_granted_by` ficam de fora.
            // A data dataria a curadoria e o `tier_granted_by` é o id do admin
            // que a concedeu — nenhum dos dois tem função no card, e o segundo
            // é identificador interno de funcionário numa resposta pública.
            'tier' => $this->tier,
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
