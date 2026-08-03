<?php

namespace App\Support;

use App\Models\PerformerPhoto;
use App\Models\PerformerProfile;
use App\Models\User;
use App\Services\PhotoAccessService;

/**
 * Como uma foto da galeria vira JSON para o front — a fonte ÚNICA da forma.
 *
 * Três superfícies consomem a mesma lista: o carrossel do perfil público (as duas
 * telas Show), o grid da tela de edição da performer e a resposta JSON dos
 * endpoints de gestão. Uma segunda cópia do mapeamento divergiria justamente na
 * URL — e uma URL montada à mão em vez de `route('performer.gallery.image', …)`
 * seria o ponto que quebra em silêncio quando a rota mudar.
 *
 * Desde o Sprint 13 a forma depende do ESPECTADOR: foto privada sem acesso vem
 * `locked:true` e SEM `url` (blur de CSS não é paywall — tile bloqueado não
 * recebe bytes, precedente do Story, § 2.3). Quem decide o que cada espectador vê
 * é o `PhotoAccessService` (dona única), o MESMO predicado que o serving usa — as
 * duas superfícies não podem divergir. A dona, passada como `$viewer`, vê todas
 * as próprias (o grid de edição sempre mostra a foto e o estado do cadeado).
 */
class PhotoGalleryPresenter
{
    /**
     * A galeria de um perfil para um espectador, na ordem do carrossel. Lê da
     * relação `photos` (já ordenada por `position`) — quem passou por eager load
     * não dispara query nova. Os estados das privadas saem em UMA query
     * (`viewerStatesFor`), não por card.
     *
     * @return array<int, array{id:int,position:int,is_private:bool,locked:bool,url:?string,access_state:string}>
     */
    public static function forProfile(PerformerProfile $profile, ?User $viewer = null): array
    {
        $states = app(PhotoAccessService::class)->viewerStatesFor($viewer, $profile);

        return $profile->photos->map(fn (PerformerPhoto $photo) => self::one($photo, $states))->all();
    }

    /**
     * @param  array<int, string>  $privateStates  photoId => granted|pending|none (só privadas)
     * @return array{id:int,position:int,is_private:bool,locked:bool,url:?string,access_state:string}
     */
    public static function one(PerformerPhoto $photo, array $privateStates = []): array
    {
        // Foto pública: visível para todos. Privada: o estado vem do batch (a
        // ausência no mapa é foto pública, já tratada acima — cai em 'granted').
        $state = $photo->is_private ? ($privateStates[(int) $photo->id] ?? 'none') : 'granted';
        $locked = $state !== 'granted';

        return [
            'id' => (int) $photo->id,
            'position' => (int) $photo->position,
            'is_private' => (bool) $photo->is_private,
            'locked' => $locked,
            // Tile bloqueado NÃO recebe URL: os bytes não podem estar a um
            // DevTools de distância. Só quem pode ver ganha o link do serving.
            'url' => $locked ? null : route('performer.gallery.image', $photo->id),
            // Para o botão do membro no tile bloqueado ('none' → "Solicitar
            // acesso"; 'pending' → "Solicitação enviada"). 'granted' quando visível.
            'access_state' => $state,
        ];
    }
}
