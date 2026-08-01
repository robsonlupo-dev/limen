<?php

namespace App\Http\Resources;

use App\Support\ActivitySlot;
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
            // "Disponível para conversa" (Sprint 11) — BOOLEANO derivado, nunca
            // o carimbo `available_for_chat_at` (que é $hidden e não sai daqui
            // em forma nenhuma). isAvailableForChat() é a dona única da janela
            // de 4h. A tela suprime o badge quando `is_live` (a bolinha já diz
            // "agora") e NÃO o mostra junto do estado — presença + localização é
            // a correlação do R2, a mesma razão de o estado sumir com is_live.
            // Aqui só sai o sinal cru; o cruzamento é decidido no card/perfil.
            'is_available' => $this->isAvailableForChat(),
            // Boost pago (Sprint 11) — BOOLEANO derivado, nunca o carimbo
            // `boosted_until` (que é $hidden e não sai daqui em forma nenhuma).
            // isBoosted() é a dona única da janela. A tela usa isto para o badge
            // "⚡ Destaque" e a borda dourada; a POSIÇÃO no topo já vem da
            // ordenação do scopePublicCatalog. Expor o timestamp diria a hora
            // exata em que o destaque acaba — presença ao minuto, a mesma
            // disciplina do is_available.
            'is_boosted' => $this->isBoosted(),
            // "Última atividade" em FAIXA (Sprint 10). String ("Ativa hoje" /
            // "esta semana" / "este mês") ou null — nunca o timestamp
            // `last_active_at`, que é $hidden no User e não sai daqui em forma
            // nenhuma. ActivitySlot é a dona única da regra; a supressão por
            // Ghost Mode / Status Invisível já aconteceu na ESCRITA (o carimbo
            // nem existe para quem tem o perk), então aqui basta traduzir o que
            // houver. "Ativa agora" (is_live) é a bolinha verde, não este
            // rótulo — a tela oculta a faixa quando is_live, para não repetir o
            // sinal em duas formas.
            'activity_label' => ActivitySlot::for($this->user?->last_active_at),
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
            // Contador da galeria (Sprint 10) para o selo "📷 N" no card. Vem do
            // `withCount('photos')` do scope publicCatalog — as duas listagens e
            // as duas telas de perfil passam por ele. Fallback em 0 para uma linha
            // carregada fora do scope (nunca acontece nas telas públicas, mas o
            // resource não deve quebrar). Foto de perfil é conteúdo público, então
            // o número exato pode aparecer — não é canal lateral como o de
            // seguidores nem privado como o de favoritos.
            'photos_count' => (int) ($this->photos_count ?? 0),
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
