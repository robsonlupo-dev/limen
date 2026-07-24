<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Segura o membro (role=consumer) que ainda não concluiu o KYC Nível 2 fora das
 * áreas de membro, redirecionando para o envio da selfie.
 *
 * Só age sobre consumer em pending_kyc: performer e admin passam direto (o KYC
 * do membro não é da relação deles), e o consumer já 'active' também. Por isso
 * pode entrar num grupo compartilhado sem afetar quem não é o alvo — mesma
 * disciplina do DocumentsAccepted.
 *
 * NÃO é aplicado às próprias rotas de verificação (consumer.kyc.*), senão o
 * redirect apontaria para uma rota que ele mesmo bloqueia (loop infinito).
 *
 * Resposta por porta de auth (ver CLAUDE.md): fora de `api/*` uma exceção não
 * vira JSON, e uma requisição Inertia que recebe redirect segue o redirect no
 * cliente. 403 JSON quando o chamador espera JSON (XHR não-Inertia), redirect
 * no caminho normal.
 */
class EnsureMemberVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== 'consumer' || $user->status !== 'pending_kyc') {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(403, 'Verificação de identidade obrigatória.');
        }

        return redirect()
            ->route('consumer.kyc.index')
            ->with('error', 'Conclua a verificação de identidade para continuar.');
    }
}
