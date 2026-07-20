<?php

namespace App\Http\Requests;

use App\Models\Report;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Apelido do mapa, nunca o FQCN — ver Report::REPORTABLE_TYPES.
            'reportable_type' => ['required', 'string', Rule::in(array_keys(Report::REPORTABLE_TYPES))],
            'reportable_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', Rule::in([
                'underage_content',
                'non_consensual',
                'coercion',
                'impersonation',
                'spam',
                'other',
            ])],
            // Teto para não virar canal de upload de texto arbitrário; o campo
            // é opcional porque exigir descrição afasta a denúncia.
            'details' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * /reportar é rota WEB, e `shouldRenderJsonWhen` só converte exceção em
     * JSON dentro de api/* (bootstrap/app.php). Sem este override a falha de
     * validação voltaria como redirect 302 com erros na sessão — e o modal do
     * front, que fala por fetch/JSON, leria isso como falha opaca. Ver CLAUDE.md,
     * "Duas portas de auth".
     */
    protected function failedValidation(Validator $validator): void
    {
        if (! $this->expectsJson()) {
            parent::failedValidation($validator);

            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'Não foi possível enviar a denúncia.',
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reportable_type.in' => 'Este tipo de conteúdo não pode ser denunciado.',
            'reason.in' => 'Selecione um motivo válido.',
            'details.max' => 'A descrição pode ter no máximo 2000 caracteres.',
        ];
    }
}
