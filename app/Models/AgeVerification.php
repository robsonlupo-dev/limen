<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgeVerification extends Model
{
    /** CPF estruturalmente válido + data de nascimento declarada com 18+. */
    public const METHOD_CPF_DOB = 'cpf_dob';

    protected $fillable = ['user_id', 'method', 'cpf_hmac', 'verified_at'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
