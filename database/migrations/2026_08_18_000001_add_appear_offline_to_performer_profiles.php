<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presença da performer INVERTIDA (fix/panel-polish-v1, decisão do PO).
 *
 * O "Disponível para conversa" do Sprint 11 era opt-IN manual: um carimbo
 * (`available_for_chat_at`) que a performer ligava e que vencia sozinho em 4h.
 * Decisão do PO: a performer fica ONLINE automaticamente enquanto tem sessão
 * ativa — a presença deriva da ATIVIDADE REAL (`users.last_active_at`, mantido
 * pelo middleware TrackPerformerActivity, ver PerformerProfile::ONLINE_WINDOW_MINUTES),
 * não de um botão. Sem botão manual, não há o que expirar em 4h: ela some quando
 * a sessão encerra / fica inativa.
 *
 * O toggle deixa de ser "ficar disponível" e passa a ser um OPT-OUT: `appear_offline`.
 * Ligado, a performer fica invisível no catálogo (nunca aparece como online e a
 * faixa de atividade some) — mas CONTINUA recebendo mensagens normalmente
 * (receber mensagem NUNCA dependeu deste estado; ver ChatService).
 *
 * NOT NULL default FALSE: todo perfil nasce visível (online quando ativo). É um
 * opt-out consciente, então o default é aparecer.
 *
 * PRIVACIDADE: só para PERFORMER. Presença de MEMBRO continua nunca exposta
 * (decisão registrada) — nada aqui cria indicador de presença de membro. O flag
 * é `$hidden` no model e fora do `$fillable` (disciplina de discrete_mode / 2FA):
 * escrita só pelo endpoint dedicado, por forceFill.
 *
 * `available_for_chat_at` fica no schema como VESTIGIAL (não há drop): o código
 * não o lê nem o escreve mais; o Hard Delete segue o zerando por higiene.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->boolean('appear_offline')
                ->default(false)
                ->after('available_for_chat_at');
        });
    }

    public function down(): void
    {
        Schema::table('performer_profiles', function (Blueprint $table) {
            $table->dropColumn('appear_offline');
        });
    }
};
