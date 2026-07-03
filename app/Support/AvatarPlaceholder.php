<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Gera o avatar-placeholder padrão do Limen (inicial dourada em fundo escuro).
 * Usado como fallback quando não há foto real — no seed e no reparo de avatares
 * ausentes (ver App\Console\Commands\BackfillPerformerAvatars).
 */
class AvatarPlaceholder
{
    /** SVG 300x300 com a inicial do nome artístico. */
    public static function svg(string $stageName): string
    {
        $initial = mb_strtoupper(mb_substr(trim($stageName) ?: '?', 0, 1));
        $initial = htmlspecialchars($initial, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300">'
            . '<rect width="300" height="300" fill="#1a1a2e"/>'
            . '<circle cx="150" cy="150" r="120" fill="none" stroke="#c9a227" stroke-width="3"/>'
            . "<text x=\"150\" y=\"172\" font-family=\"Georgia, serif\" font-size=\"96\" fill=\"#c9a227\" text-anchor=\"middle\">{$initial}</text>"
            . '</svg>';
    }

    /**
     * Grava o placeholder no disco informado e devolve o path relativo.
     * O path é estável por usuário, então regravar é idempotente.
     */
    public static function store(string $disk, int $userId, string $stageName): string
    {
        $path = "performer-media/{$userId}/avatar.svg";
        Storage::disk($disk)->put($path, self::svg($stageName));

        return $path;
    }
}
