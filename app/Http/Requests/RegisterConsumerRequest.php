<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterConsumerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required', 'string', 'confirmed', 'min:8',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
            ],
            'birthdate' => [
                'required', 'date', 'before_or_equal:today',
                'before_or_equal:' . now()->subYears(18)->format('Y-m-d'),
            ],
            'phone' => ['nullable', 'string', 'max:20'],
            'accept_terms' => ['required', 'accepted'],
            'lgpd_consent' => ['required', 'accepted'],
            'terms_version' => ['required', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'birthdate.before_or_equal' => 'You must be at least 18 years old to register.',
            'password.regex' => 'Password must contain at least one uppercase letter and one number.',
            'accept_terms.accepted' => 'You must accept the terms of service.',
            'lgpd_consent.accepted' => 'You must consent to the LGPD data processing terms.',
        ];
    }
}
