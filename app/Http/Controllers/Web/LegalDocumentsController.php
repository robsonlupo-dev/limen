<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\DocumentAcceptance;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Textos jurídicos, em rota pública.
 *
 * Pública de propósito: a performer precisa conseguir LER o documento antes de
 * ter conta, e o link tem que continuar abrindo depois — um contrato que só o
 * signatário logado enxerga não serve como referência.
 *
 * O conteúdo é placeholder até o escritório (Opice Blum) entregar o texto. A
 * versão exibida vem do mesmo config que o aceite grava, então a página nunca
 * mostra um texto rotulado com versão diferente da que seria registrada.
 */
class LegalDocumentsController extends Controller
{
    public function contentPolicy(): Response
    {
        return Inertia::render('Legal/Document', [
            'document' => [
                'type' => DocumentAcceptance::TYPE_CONTENT_POLICY,
                'title' => 'Política de Conteúdo Proibido',
                'version' => DocumentAcceptance::currentVersion(DocumentAcceptance::TYPE_CONTENT_POLICY),
            ],
        ]);
    }

    public function performanceContract(): Response
    {
        return Inertia::render('Legal/Document', [
            'document' => [
                'type' => DocumentAcceptance::TYPE_PERFORMANCE_CONTRACT,
                'title' => 'Contrato de Performance',
                'version' => DocumentAcceptance::currentVersion(DocumentAcceptance::TYPE_PERFORMANCE_CONTRACT),
            ],
        ]);
    }
}
