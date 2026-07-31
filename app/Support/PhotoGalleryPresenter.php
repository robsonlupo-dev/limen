<?php

namespace App\Support;

use App\Models\PerformerPhoto;
use App\Models\PerformerProfile;

/**
 * Como uma foto da galeria vira JSON para o front — a fonte ÚNICA da forma.
 *
 * Três superfícies consomem a mesma lista: o carrossel do perfil público (as duas
 * telas Show), o grid da tela de edição da performer e a resposta JSON dos
 * endpoints de gestão. Uma segunda cópia do mapeamento divergiria justamente na
 * URL — e uma URL montada à mão em vez de `route('performer.photos.image', …)`
 * seria o ponto que quebra em silêncio quando a rota mudar.
 *
 * Tudo aqui é público: a URL do serving é pública (é o perfil público) e a
 * posição é a ordem do carrossel, que é visível de qualquer forma. Não há campo
 * sensível a filtrar por lado, diferente do favorito.
 */
class PhotoGalleryPresenter
{
    /**
     * A galeria de um perfil, na ordem do carrossel. Lê da relação `photos`
     * (já ordenada por `position`) — quem passou por eager load não dispara
     * query nova.
     *
     * @return array<int, array{id:int,url:string,position:int}>
     */
    public static function forProfile(PerformerProfile $profile): array
    {
        return $profile->photos->map(self::one(...))->all();
    }

    /**
     * @return array{id:int,url:string,position:int}
     */
    public static function one(PerformerPhoto $photo): array
    {
        return [
            'id' => $photo->id,
            'url' => route('performer.gallery.image', $photo->id),
            'position' => $photo->position,
        ];
    }
}
