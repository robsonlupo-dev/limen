<?php

namespace App\Http\Requests;

use App\Models\PerformerProfile;
use Illuminate\Foundation\Http\FormRequest;

class SendTipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'performer_slug' => ['required', 'string'],
            'amount' => ['required', 'integer', 'min:1', 'max:1000'],
            'message' => ['nullable', 'string', 'max:200'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function resolvedPerformer(): PerformerProfile
    {
        return PerformerProfile::with('user')
            ->where('slug', $this->validated('performer_slug'))
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->where('is_verified', true)
            ->firstOrFail();
    }
}
