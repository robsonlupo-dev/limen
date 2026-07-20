<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAcceptance extends Model
{
    /** Política de Conteúdo Proibido. */
    public const TYPE_CONTENT_POLICY = 'content_policy';

    /** Contrato de Performance. */
    public const TYPE_PERFORMANCE_CONTRACT = 'performance_contract';

    /** Documentos exigidos hoje. Ordem = ordem de exibição na tela de aceite. */
    public const REQUIRED = [
        self::TYPE_CONTENT_POLICY,
        self::TYPE_PERFORMANCE_CONTRACT,
    ];

    protected $fillable = [
        'user_id', 'document_type', 'document_version',
        'accepted_at', 'ip_address_hash', 'user_agent_hash',
    ];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    /**
     * Um aceite gravado nunca é editado: versão nova gera linha nova. Corrigir
     * uma linha existente destruiria a evidência que a tabela existe para dar.
     * Guarda no model, não só no comentário da migration.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \RuntimeException('document_acceptances é append-only: registre um novo aceite.');
        });
    }

    /**
     * Versão vigente do documento, do config — nunca de input do usuário.
     *
     * Explode em vez de virar string vazia: config cacheado velho ou typo em
     * DOC_VERSION_* gravaria aceites com `document_version = ''`, evidência
     * jurídica sem referência a texto nenhum. Falha alto e cedo.
     */
    public static function currentVersion(string $type): string
    {
        $version = config("documents.versions.{$type}");

        if (! is_string($version) || $version === '') {
            throw new \RuntimeException("Versão do documento '{$type}' não configurada (config/documents.php).");
        }

        return $version;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
