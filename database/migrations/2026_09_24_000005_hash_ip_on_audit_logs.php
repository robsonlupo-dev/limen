<?php

use App\Support\ClientFingerprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `audit_logs.ip` deixa de guardar o IP em claro (security/audit-ip-hash).
 *
 * O IP é dado pessoal (LGPD) e o `audit_logs` é o dossiê por `user_id`: com o IP
 * cru, um dump do banco correlacionava contas por origem SEM a APP_KEY —
 * exatamente o que o HMAC do aceite de documentos e do cadastro já impediam na
 * própria tabela deles (SECURITY_ISSUES: "Aceite de documentos — IP em claro" e
 * "Flag de IP de cadastro compartilhado §3"). A coluna passa a `ip_hash` para o
 * schema declarar o que guarda; o valor é o HMAC-SHA256 com a APP_KEY (mesmo
 * `ClientFingerprint` do aceite). HMAC preserva igualdade, então "estas contas
 * vieram do mesmo IP" continua respondível; só o octeto cru some.
 *
 * As linhas existentes são re-hasheadas aqui (dev/staging são sintéticos; prod
 * ainda não lançou). Rotacionar a APP_KEY invalida os digests antigos — a linha
 * segue sendo a trilha; o fingerprint é que deixa de ser conferível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->renameColumn('ip', 'ip_hash');
        });

        // Re-hash do que já existe: o valor cru não pode sobreviver à migração.
        DB::table('audit_logs')
            ->whereNotNull('ip_hash')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('audit_logs')
                        ->where('id', $row->id)
                        ->update(['ip_hash' => ClientFingerprint::hash($row->ip_hash)]);
                }
            });
    }

    public function down(): void
    {
        // Só reverte o nome da coluna — os IPs crus não voltam (são irreversíveis
        // por construção do HMAC), e é isso mesmo que se quer.
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->renameColumn('ip_hash', 'ip');
        });
    }
};
