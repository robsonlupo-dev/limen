<?php

namespace App\Http\Middleware;

use App\Services\DocumentAcceptanceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Barra a performer que não aceitou a versão vigente dos documentos.
 *
 * Só age sobre performers: membro e admin passam direto — os documentos são da
 * relação de trabalho da performer com a plataforma, não do uso do site.
 *
 * Não é aplicado à própria tela de aceite nem às páginas dos textos, senão o
 * redirect apontaria para uma rota que ele mesmo bloqueia (loop infinito).
 *
 * Resposta por porta de auth (ver CLAUDE.md): fora de `api/*` uma exceção não
 * vira JSON, e uma requisição Inertia que recebe redirect segue o redirect no
 * cliente. Por isso redirect no caminho normal e 403 JSON quando o chamador
 * espera JSON — um XHR seguindo redirect receberia HTML e quebraria.
 */
class DocumentsAccepted
{
    public function __construct(private DocumentAcceptanceService $acceptances) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== 'performer' || $this->acceptances->hasAcceptedAll($user)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(403, 'Aceite dos documentos obrigatório.');
        }

        return redirect()
            ->route('performer.documents')
            ->with('error', 'Aceite a Política de Conteúdo Proibido e o Contrato de Performance para continuar.');
    }
}
