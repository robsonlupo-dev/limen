<?php

namespace App\Models;

use App\Support\CpfHash;
use App\Support\DocumentHash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Lista negra antifraude: derivados unidirecionais (HMAC) de CPF/documento de
 * contas banidas, para sinalizar recadastro. NUNCA guarda PII crua.
 *
 * `$fillable` vazio de propósito: a escrita passa só por addEntry()/
 * recordForBannedUser() — nenhum caminho de mass assignment alcança os hashes
 * ou a referência da conta banida.
 */
class FraudBlacklist extends Model
{
    protected $table = 'fraud_blacklist';

    /** Escrita só via métodos dedicados (forceFill). */
    protected $fillable = [];

    public static function hasCpfHash(string $hash): bool
    {
        return static::where('cpf_hash', $hash)->exists();
    }

    public static function hasDocumentHash(string $hash): bool
    {
        return static::where('document_hash', $hash)->exists();
    }

    /**
     * Registra uma entrada. Idempotente por `cpf_hash`: se o CPF já está
     * listado (outra conta da mesma pessoa foi banida antes), devolve a entrada
     * existente em vez de estourar o índice único — a lista quer saber SE a
     * pessoa foi banida, não quantas vezes.
     */
    public static function addEntry(
        User $bannedUser,
        string $cpfHash,
        ?string $documentHash,
        int $bannedBy,
        string $reason,
    ): self {
        $existing = static::where('cpf_hash', $cpfHash)->first();
        if ($existing) {
            return $existing;
        }

        $entry = new self;
        $entry->forceFill([
            'cpf_hash' => $cpfHash,
            'document_hash' => $documentHash,
            'banned_user_id' => $bannedUser->id,
            'banned_by' => $bannedBy,
            'reason' => $reason,
        ]);

        try {
            $entry->save();
        } catch (UniqueConstraintViolationException) {
            // Corrida: dois bans de contas distintas do MESMO CPF passaram pelo
            // check acima antes de qualquer INSERT. O unique é a autoridade —
            // devolve a linha que venceu em vez de estourar e reverter o ban.
            return static::where('cpf_hash', $cpfHash)->firstOrFail();
        }

        return $entry;
    }

    /**
     * Deriva os hashes de um usuário recém-banido e cria a entrada. Chamado de
     * dentro da transação do ban.
     *
     * Fontes, nesta ordem:
     *  - KYC (performer): `document_number` é o CPF em claro (cast encrypted) —
     *    rende cpf_hash E document_hash.
     *  - age_verifications (membro): `cpf_hmac` já é o HMAC pronto — rende só
     *    cpf_hash (o membro não tem documento de KYC).
     *
     * Sem nenhuma das duas fontes (conta sem verificação): não há o que
     * registrar — devolve null, o ban segue normalmente.
     */
    public static function recordForBannedUser(User $user, int $bannedBy, string $reason): ?self
    {
        $cpfHash = null;
        $documentHash = null;

        // Qualquer verificação com número de documento serve — inclusive
        // `pending` e `rejected`. O caso mais comum de ban de performer é
        // JUSTAMENTE com o KYC ainda `pending` (conteúdo proibido / documento
        // falso é pego na fila de revisão, antes de aprovar); filtrar por
        // approved/review deixaria escapar exatamente quem a lista quer barrar,
        // e como o performer não tem age_verification, nada seria gravado.
        $verification = $user->identityVerifications()
            ->whereNotNull('document_number')
            ->latest('id')
            ->first();

        if ($verification && $verification->document_number) {
            // document_number == CPF neste schema (KycSubmissionService).
            $cpfHash = CpfHash::make($verification->document_number);
            $documentHash = DocumentHash::make($verification->document_number);
        } else {
            // Membro: o HMAC do CPF já está pronto em age_verifications.
            $cpfHash = AgeVerification::where('user_id', $user->id)
                ->whereNotNull('cpf_hmac')
                ->value('cpf_hmac');
        }

        if (! $cpfHash) {
            return null;
        }

        return static::addEntry($user, $cpfHash, $documentHash, $bannedBy, $reason);
    }

    public function bannedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'banned_user_id');
    }

    public function bannedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'banned_by');
    }
}
