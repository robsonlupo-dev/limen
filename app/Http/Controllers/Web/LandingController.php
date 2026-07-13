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

        return Inertia::render('Landing', [
            'meta' => [
                'title' => 'Limen — O Portal Exclusivo para Criadores Verificados no Brasil',
                'description' => 'Entre na lista de espera do Limen. A plataforma premium brasileira de conteúdo verificado, pagamentos via PIX e privacidade total.',
                'canonical' => $publicBase.'/',
                'og_title' => 'Limen — O Portal Exclusivo para Criadores Verificados no Brasil',
                'og_description' => 'Entre na lista de espera do Limen. A plataforma premium brasileira de conteúdo verificado, pagamentos via PIX e privacidade total.',
                'og_url' => $publicBase.'/',
                'og_image' => $publicBase.'/og-image.png',
            ],
        ]);
    }
}
