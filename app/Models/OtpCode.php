<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Código OTP de acesso passwordless (Sprint 11).
 *
 * Uma linha por código emitido. O ciclo de vida (emissão, invalidação dos
 * anteriores, rate limit, verificação) vive no OtpService — este model é a linha
 * e as regras que dependem só dela (expirou? foi usado? estourou os palpites?).
 *
 * `code` é credencial: `$hidden` para nunca vazar numa serialização acidental, e
 * FORA do `$fillable` — nasce só pelo OtpService, nunca de um payload de request
 * (mesma disciplina de `discrete_mode`/2FA). A comparação com o código digitado é
 * feita no service com `hash_equals` (tempo constante).
 */
class OtpCode extends Model
{
    use HasFactory;

    /** Palpites errados antes de o código ser queimado. */
    public const MAX_ATTEMPTS = 5;

    /** Validade do código, em minutos. */
    public const EXPIRY_MINUTES = 5;

    /** A linha nasce e morre; `used_at`/`attempts` são o estado, não o ORM. */
    public $timestamps = false;

    protected $fillable = [
        'expires_at',
        'used_at',
        'attempts',
    ];

    protected $hidden = [
        'code',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'created_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Um código de 6 dígitos, com zeros à esquerda preservados.
     *
     * `random_int` (CSPRNG), nunca `rand`/`mt_rand`: é material de autenticação.
     * O espaço é 000000–999999; o que protege não é a entropia (só 10^6), e sim
     * o TTL de 5 min + os 5 palpites por código + os 3/hora.
     */
    public static function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    /**
     * Ainda pode ser tentado? Não usado, não expirado e dentro dos palpites.
     * É a condição que o OtpService reavalia na verificação.
     */
    public function isConsumable(): bool
    {
        return ! $this->isUsed()
            && ! $this->isExpired()
            && $this->attempts < self::MAX_ATTEMPTS;
    }
}
