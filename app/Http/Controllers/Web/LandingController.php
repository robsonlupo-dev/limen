<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LandingController extends Controller
{
    public function index(): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('catalog');
        }

        // SEO/OG rendered server-side from this prop (Inertia SSR is off, so a
        // client <Head> is invisible to social scrapers). Canonical/OG point at
        // the public production domain regardless of the host that served it.
        $publicBase = 'https://thelimen.com.br';

        // Landing cinematográfica: a tagline é o cartão social. A prévia ao
        // compartilhar (WhatsApp/Google) é o wordmark dourado (moldura.webp).
        $tagline = 'O portal do desejo, verificado e real.';

        return Inertia::render('Landing', [
            'meta' => [
                'title' => 'Limen — O portal do desejo, verificado e real',
                'description' => $tagline,
                'canonical' => $publicBase.'/',
                'og_title' => 'Limen',
                'og_description' => $tagline,
                'og_url' => $publicBase.'/',
                'og_image' => $publicBase.'/landing/moldura.webp',
            ],
        ]);
    }
}
