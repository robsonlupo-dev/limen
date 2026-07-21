<?php

namespace App\Http\Controllers\Web\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Porta web (sessão + CSRF) do direito de eliminação. Fora de `role:consumer`
 * de propósito: performer também é titular de dados e também tem art. 18.
 */
class DeletionController extends Controller
{
    public function __construct(private DeletionService $deletion) {}

    public function request(Request $request): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $this->deletion->requestDeletion($user);
        } catch (RuntimeException) {
            // Erro consumível pelo front: rota web, fora de api/*, então a
            // exceção não viraria JSON sozinha (CLAUDE.md, "Duas portas de auth").
            return $this->fail(
                $request,
                'payout_pending',
                'Você tem um saque em andamento. Assim que ele for concluído, a exclusão fica disponível.',
            );
        }

        return $this->ok(
            $request,
            'Pedido registrado. Enviamos um e-mail com o prazo e o link para cancelar.',
        );
    }

    public function cancel(Request $request): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->deletion->cancelDeletion($user)) {
            return $this->fail($request, 'not_cancellable', 'Não há pedido de exclusão em aberto.');
        }

        return $this->ok($request, 'Pedido de exclusão cancelado. Sua conta continua ativa.');
    }

    /**
     * Link do e-mail. Só RENDERIZA — o token é consumido no POST.
     *
     * GET não pode mutar aqui: o prefetch de link que várias caixas de e-mail
     * fazem sozinhas dispararia a confirmação sem ninguém ter clicado, e o
     * token é de uso único. Mesmo padrão do unsubscribe da waitlist.
     */
    public function confirm(string $token): Response
    {
        $user = $this->deletion->userForToken($token);

        return Inertia::render('Account/ConfirmDeletion', [
            // Token de volta para o form só quando é válido — não ecoamos de
            // volta um valor arbitrário vindo da URL.
            'token' => $user ? $token : null,
            'valid' => $user !== null,
            'scheduledAt' => $user?->deletion_scheduled_at?->toIso8601String(),
        ]);
    }

    /** Consome o token (uso único) e marca a confirmação por e-mail. */
    public function confirmStore(Request $request): RedirectResponse
    {
        $token = (string) $request->input('token');
        $user = $this->deletion->userForToken($token);

        // Resposta única para token inválido, expirado e já usado: distinguir
        // os três diria a quem tem a URL em mãos se ela um dia foi válida.
        if ($user === null) {
            return back()->with('error', 'Link inválido ou expirado.');
        }

        $this->deletion->confirmDeletion($user);

        return back()->with('success', 'Pedido de exclusão confirmado.');
    }

    private function ok(Request $request, string $message): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message], 200)
            : back()->with('success', $message);
    }

    private function fail(Request $request, string $reason, string $message): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['reason' => $reason, 'message' => $message], 422)
            : back()->with('error', $message);
    }
}
