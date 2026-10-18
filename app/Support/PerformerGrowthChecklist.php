<?php

namespace App\Support;

use App\Models\LiveSession;
use App\Models\PerformerProfile;
use App\Models\PerformerStory;

/**
 * Checklist de crescimento do painel da performer (roadmap Fatal Model, Onda A).
 *
 * Gamifica, numa lista curta, os MESMOS sinais que o ranking/descoberta usam —
 * sem inventar métrica nova e sem superfície pública. É só-leitura e DERIVA de
 * dados que já existem (perfil + stories + live), então não há coluna nem
 * persistência: a cada abertura do painel a lista reflete o estado atual.
 *
 * Diferente da barra de "completude do perfil" (profileProgress no
 * DashboardController), que mede o preenchimento estático do perfil. Aqui o foco
 * é AÇÃO recorrente: publicar stories, ficar ao vivo, ter voz — o que move o card
 * no catálogo.
 */
class PerformerGrowthChecklist
{
    /** Meta de stories na janela semanal. */
    public const STORIES_PER_WEEK = 3;

    /**
     * O `href` leva direto à tela onde se completa o item (gerado no servidor
     * com route(), para não depender do allowlist do Ziggy). O painel usa só os
     * pendentes como link — item feito não precisa de ação.
     *
     * @return array{items: list<array{key: string, label: string, done: bool, hint: string, href: string}>, done: int, total: int}
     */
    public static function for(PerformerProfile $profile): array
    {
        $storiesThisWeek = PerformerStory::where('performer_profile_id', $profile->id)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        $items = [
            [
                'key' => 'voz',
                'label' => 'Grave sua apresentação de voz',
                'done' => $profile->hasApprovedVoiceIntro(),
                'hint' => 'Perfis com voz convertem mais — é a sua assinatura.',
                'href' => route('performer.voice-intro.edit'),
            ],
            [
                'key' => 'capa',
                'label' => 'Adicione uma foto de capa',
                'done' => (bool) $profile->cover_path,
                'hint' => 'A capa é a primeira impressão no seu perfil.',
                'href' => route('performer.profile.edit'),
            ],
            [
                'key' => 'frase',
                'label' => 'Escreva sua frase de destaque',
                'done' => filled($profile->headline),
                'hint' => 'Uma frase curta que aparece no seu card no catálogo.',
                'href' => route('performer.profile.edit'),
            ],
            [
                'key' => 'stories_semana',
                'label' => 'Publique '.self::STORIES_PER_WEEK.' stories esta semana',
                'done' => $storiesThisWeek >= self::STORIES_PER_WEEK,
                'hint' => "Você publicou {$storiesThisWeek} nos últimos 7 dias. Stories te mantêm no topo da trilha.",
                // panel.stories é a PÁGINA (Inertia) de gerenciar stories; a
                // stories.index é o endpoint JSON que o painel consome — mandar o
                // Link do checklist para lá quebra o Inertia ("plain JSON response").
                'href' => route('performer.panel.stories'),
            ],
        ];

        // "Ao vivo este mês" só entra quando a live está ligada (dark launch): com
        // a feature desligada em produção, pedir para "ficar ao vivo" levaria a uma
        // tela que o middleware recusaria.
        if (config('features.live_enabled')) {
            $liveThisMonth = LiveSession::where('performer_profile_id', $profile->id)
                ->where('created_at', '>=', now()->startOfMonth())
                ->count();

            $items[] = [
                'key' => 'ao_vivo_mes',
                'label' => 'Faça uma transmissão ao vivo este mês',
                'done' => $liveThisMonth > 0,
                'hint' => 'O "Agora" (ao vivo) é o topo do catálogo em tempo real.',
                'href' => route('performer.live'),
            ];
        }

        $done = count(array_filter($items, fn (array $i) => $i['done']));

        return [
            'items' => $items,
            'done' => $done,
            'total' => count($items),
        ];
    }
}
