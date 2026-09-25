<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatCatalogTemplate;
use App\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * CRUD das MENSAGENS DE CATÁLOGO pré-cadastradas (feat/catalog-message-templates).
 * Protegido por auth + admin.access (moderador NÃO alcança) — é copy de plataforma,
 * como o catálogo de presentes, não conteúdo de usuário.
 *
 * As 15 iniciais nascem na migration; aqui o admin edita o texto, ativa/desativa,
 * reordena e adiciona/remove — sem deploy. `{nome}` no corpo vira o apelido do
 * membro no envio (some se ele não tiver). Nunca há PII aqui: é texto fixo nosso.
 */
class CatalogMessagesController extends Controller
{
    public function index(): View
    {
        return view('admin.catalog-messages', [
            'templates' => ChatCatalogTemplate::orderBy('position')->orderBy('id')->get(),
            'activeCount' => ChatCatalogTemplate::where('is_active', true)->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.(int) config('chat.max_length')],
            'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $template = ChatCatalogTemplate::create([
            'body' => $data['body'],
            'position' => $data['position'] ?? ((int) ChatCatalogTemplate::max('position') + 1),
            'is_active' => true,
        ]);

        Audit::log('catalog_template.created', $template);

        return redirect()->route('admin.catalog-messages')->with('success', 'Mensagem adicionada.');
    }

    public function update(Request $request, ChatCatalogTemplate $template): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.(int) config('chat.max_length')],
            'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $template->update([
            'body' => $data['body'],
            'position' => $data['position'] ?? $template->position,
            'is_active' => $request->boolean('is_active'),
        ]);

        Audit::log('catalog_template.updated', $template);

        return redirect()->route('admin.catalog-messages')->with('success', 'Mensagem atualizada.');
    }

    public function destroy(Request $request, ChatCatalogTemplate $template): RedirectResponse
    {
        $template->delete();

        Audit::log('catalog_template.deleted', $template);

        return redirect()->route('admin.catalog-messages')->with('success', 'Mensagem removida.');
    }
}
