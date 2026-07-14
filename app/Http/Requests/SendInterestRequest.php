<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class SendInterestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'member_id' => ['required', 'integer'],
        ];
    }

    public function resolvedMember(): User
    {
        // Só membros (consumers) ativos podem receber interesse. Não revelar o
        // opt-out aqui: o serviço trata o opt-out silenciosamente.
        return User::where('id', $this->validated('member_id'))
            ->where('role', 'consumer')
            ->where('status', 'active')
            ->firstOrFail();
    }
}
