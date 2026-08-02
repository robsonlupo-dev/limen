<?php

namespace App\Http\Requests\Moderation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Atualização de uma denúncia pelo moderador.
 *
 * A autorização de QUEM (moderator/admin) é do middleware `moderator.access` na
 * rota — aqui só valida o payload. O status é fechado a um allowlist: os mesmos
 * três destinos que o painel de admin já permite (`reviewed`/`resolved`/
 * `dismissed`). `pending` NÃO está na lista de propósito — a fila é o estado
 * inicial, não um destino de revisão.
 *
 * `action_taken` da spec do Sprint 13 mapeia em `resolved`: o enum do banco
 * (create_reports_table) tem `resolved`, e inventar um valor novo divergiria da
 * fila de admin que já usa `resolved` para "ação tomada".
 */
class UpdateReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['reviewed', 'resolved', 'dismissed'])],
            'moderator_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
