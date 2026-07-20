<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\AcceptDocumentsRequest;
use App\Models\DocumentAcceptance;
use App\Services\DocumentAcceptanceService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DocumentAcceptanceController extends Controller
{
    public function __construct(private DocumentAcceptanceService $acceptances) {}

    public function index(Request $request): Response
    {
        $pending = $this->acceptances->pendingFor($request->user());

        return Inertia::render('Performer/AcceptDocuments', [
            'documents' => [
                [
                    'type' => DocumentAcceptance::TYPE_CONTENT_POLICY,
                    'title' => 'Política de Conteúdo Proibido',
                    'version' => DocumentAcceptance::currentVersion(DocumentAcceptance::TYPE_CONTENT_POLICY),
                    'url' => route('legal.content-policy'),
                    'pending' => in_array(DocumentAcceptance::TYPE_CONTENT_POLICY, $pending, true),
                ],
                [
                    'type' => DocumentAcceptance::TYPE_PERFORMANCE_CONTRACT,
                    'title' => 'Contrato de Performance',
                    'version' => DocumentAcceptance::currentVersion(DocumentAcceptance::TYPE_PERFORMANCE_CONTRACT),
                    'url' => route('legal.performance-contract'),
                    'pending' => in_array(DocumentAcceptance::TYPE_PERFORMANCE_CONTRACT, $pending, true),
                ],
            ],
            // Já aceitou tudo e caiu aqui direto: a tela vira revisão, com o
            // caminho de volta explícito em vez de um formulário sem efeito.
            'isRevision' => $pending === [],
        ]);
    }

    public function store(AcceptDocumentsRequest $request): RedirectResponse
    {
        $user = $request->user();

        $this->acceptances->acceptAll($user, $request);

        // O audit log guarda as versões, não os checkboxes: é o que responde
        // "sob qual texto ela aceitou" sem precisar cruzar com o config de hoje.
        Audit::log('performer_documents_accepted', $user, [
            'versions' => collect(DocumentAcceptance::REQUIRED)
                ->mapWithKeys(fn (string $type) => [$type => DocumentAcceptance::currentVersion($type)])
                ->all(),
        ], $request);

        return redirect()
            ->route($user->status === 'active' ? 'performer.dashboard' : 'performer.onboarding')
            ->with('success', 'Documentos aceitos.');
    }
}
