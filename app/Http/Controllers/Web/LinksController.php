<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class LinksController extends Controller
{
    /**
     * Public link-in-bio hub (Linktree replacement) shared from social bios.
     * No auth, no age gate — it only holds outbound links, no adult content.
     * The `meta` prop is rendered server-side in the root Blade (Inertia SSR is
     * off) so social previews resolve for scrapers that don't execute JS.
     */
    public function index(): Response
    {
        // Canonical/OG point at the public production domain (thelimen.com.br),
        // regardless of which host served the request (staging shares this app).
        $publicBase = 'https://thelimen.com.br';

        return Inertia::render('Links', [
            'meta' => [
                'title' => 'Links oficiais · Limen',
                'description' => 'Todos os canais oficiais do Limen: lista de espera, Instagram, TikTok, Telegram, YouTube e X. O portal do desejo, verificado e real. +18',
                'canonical' => $publicBase.'/links',
                'og_title' => 'Links oficiais · Limen',
                'og_description' => 'O portal do desejo, verificado e real. Entre na lista de espera e siga os canais oficiais. +18',
                'og_url' => $publicBase.'/links',
                'og_image' => $publicBase.'/og-image.png',
            ],
        ]);
    }
}
