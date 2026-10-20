<?php

namespace App\Support;

use App\Models\PerformerProfile;

/**
 * Marca de ORIGEM (Onda B §4) — o texto da marca d'água de branding/atribuição
 * queimada em toda foto da performer no upload. Dona única da DECISÃO (ligar? que
 * texto?); o DESENHO (diagonal, opacidade) é do WatermarkService, que esta marca
 * reusa sem alteração — só o texto muda.
 *
 * Não confundir com a marca FORENSE (Fã #NNNN por espectador): aquela é por
 * entrega/par e identifica quem vazou; a origem é IGUAL para todos e só divulga.
 */
final class OriginMark
{
    /**
     * A marca de origem deve ser aplicada? Liga por config E exige a fonte no
     * disco — sem a fonte o `WatermarkService` lançaria e, como a origem roda em
     * TODO upload de foto, derrubaria todos eles. Então aqui é fail-OPEN: fonte
     * ausente → marca desligada, upload segue sem branding (o forense da encomenda
     * tem fail-closed próprio, que não passa por este gate).
     */
    public static function enabled(): bool
    {
        if (! (bool) config('origin_mark.enabled', true)) {
            return false;
        }

        $font = (string) config('custom_order.watermark.font');

        return $font !== '' && is_file($font);
    }

    /**
     * A marca de origem no VÍDEO deve ser aplicada? Interruptor PRÓPRIO (nasce OFF —
     * vídeo é o caminho caro), também condicionado à fonte existir (mesmo fail-open de
     * branding da foto). Separado de enabled() de propósito: a foto pode estar ligada e
     * o vídeo desligado até o go-live.
     */
    public static function videoEnabled(): bool
    {
        if (! (bool) config('origin_mark.video_enabled', false)) {
            return false;
        }

        $font = (string) config('custom_order.watermark.font');

        return $font !== '' && is_file($font);
    }

    /**
     * O texto da marca para esta performer: "Limen · @slug" quando ela mantém o
     * @ ligado (padrão, divulgação), ou só "Limen" quando o desliga. O slug já é
     * público (vai na URL do perfil) — carimbá-lo não expõe nada novo.
     */
    public static function textFor(PerformerProfile $profile): string
    {
        $withHandle = $profile->origin_mark_handle ?? (bool) config('origin_mark.handle_default', true);

        if ($withHandle && $profile->slug !== null && $profile->slug !== '') {
            return 'Limen · @'.$profile->slug;
        }

        return 'Limen';
    }

    /**
     * O texto da marca SE a origem estiver ligada; senão null. Açúcar para os
     * callers do ContentStore::store, que passam o texto direto (null = sem marca).
     */
    public static function textIfEnabled(PerformerProfile $profile): ?string
    {
        return self::enabled() ? self::textFor($profile) : null;
    }
}
