<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdentityVerification extends Model
{
    protected $fillable = [
        'user_id', 'document_type', 'document_number', 'full_legal_name',
        'date_of_birth', 'document_front_path', 'document_back_path',
        'selfie_path', 'provider', 'provider_reference', 'provider_status',
        'status', 'age_confirmed', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'document_number' => 'encrypted',
            'full_legal_name' => 'encrypted',
            'date_of_birth' => 'encrypted',
            'age_confirmed' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
