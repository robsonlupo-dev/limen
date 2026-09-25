<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mensagens de catálogo PRÉ-CADASTRADAS (feat/catalog-message-templates).
 *
 * As 15 mensagens grátis diárias da performer (o alcance ao catálogo de membros)
 * deixam de ser TEXTO LIVRE e passam a ser a escolha de um destes modelos. Motivo
 * de negócio: o texto livre grátis era um vetor de fuga — a performer podia mandar
 * Instagram/WhatsApp e tirar o membro da plataforma sem gastar nada. Modelos
 * definidos por nós fecham isso por construção (ela não digita) e nascem seguros
 * (sem contato, sem transação). No chat JÁ PAGO o texto segue livre — lá o membro
 * já foi monetizado. Ver docs/DECISOES_2026-08 / PENDENCIAS.
 *
 * Editáveis no admin (tela `admin.catalog-messages`). `{nome}` no corpo vira o
 * apelido do membro no envio; sem apelido, some (ChatCatalogTemplate::render).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_catalog_templates', function (Blueprint $table) {
            $table->id();
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });

        // Semente inicial: as 15 aprovadas pelo PO. Só insere se a tabela está
        // vazia (idempotente em re-run). O admin edita/adiciona/remove depois.
        if (DB::table('chat_catalog_templates')->count() > 0) {
            return;
        }

        $now = now();
        $messages = [
            'Oi… vi você por aqui e fiquei curiosa. O que te trouxe até mim?',
            'Confesso: seu perfil me chamou atenção. Vamos trocar uma ideia?',
            'Tô começando o dia e queria uma companhia interessante pra conversar. Topa?',
            'Não costumo dar o primeiro passo, mas com você abri exceção. Me responde?',
            'Fiquei com vontade de saber como é a sua voz. Me manda um oi?',
            'Que tal a gente sair do "talvez" e começar de verdade? Tô te esperando.',
            'Tenho um lado que só mostro pra quem conversa comigo. Quer conhecer?',
            'Aposto que você não tem coragem de puxar assunto comigo. Me prova que eu tô errada 😏',
            'Acordei com vontade de uma conversa gostosa hoje. Você me faz companhia?',
            'Seu jeito me deixou curiosa. Me conta algo que ninguém sabe sobre você?',
            'Prometo ser péssima em papo sem graça e ótima no que interessa. Bora testar?',
            'Se você respondesse agora, faria meu dia bem melhor. Que tal?',
            'Tô com tempo e vontade de te dar atenção de verdade. Me chama?',
            'Já imaginei como seria a nossa primeira conversa. Vem descobrir comigo?',
            'Gostei de você à primeira vista. Vamos ver se a conversa combina também?',
        ];

        $rows = [];
        foreach ($messages as $i => $body) {
            $rows[] = [
                'body' => $body,
                'is_active' => true,
                'position' => $i + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('chat_catalog_templates')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_catalog_templates');
    }
};
