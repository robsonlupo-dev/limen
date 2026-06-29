<?php

namespace App\Http\Requests;

use App\Rules\CpfValido;
use Illuminate\Foundation\Http\FormRequest;

class SubmitKycRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', 'in:cpf,rg,cnh'],
            'cpf' => ['required', 'string', new CpfValido],
            'full_legal_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => [
                'required', 'date',
                'before_or_equal:' . now()->subYears(18)->format('Y-m-d'),
            ],
            'document_front' => ['required', 'file', 'mimes:jpeg,png', 'max:10240'],
            'document_back' => ['nullable', 'file', 'mimes:jpeg,png', 'max:10240'],
            'selfie' => ['required', 'file', 'mimes:jpeg,png', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'date_of_birth.before_or_equal' => 'Você deve ter pelo menos 18 anos.',
            'document_front.mimes' => 'O documento deve ser jpeg ou png.',
            'document_back.mimes' => 'O documento deve ser jpeg ou png.',
            'selfie.mimes' => 'A selfie deve ser jpeg ou png.',
            'document_front.max' => 'O arquivo não pode exceder 10MB.',
            'document_back.max' => 'O arquivo não pode exceder 10MB.',
            'selfie.max' => 'O arquivo não pode exceder 10MB.',
        ];
    }
}
