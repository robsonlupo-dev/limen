<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Models\Report;
use App\Services\ChatAudioStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recolhe os BYTES de áudio das mensagens de voz já fora da retenção do chat
 * (feat/chat-audio-retention-gc).
 *
 * ── Por que este comando existe ──────────────────────────────────────────────
 * O áudio é a parte PESADA do chat (texto é irrelevante). A retenção do chat
 * (`ChatAccessService::purgeExpired`) SOFT-DELETA as linhas das mensagens ao fim
 * da carência (access_days + grace_days), mas NÃO tocava nos MP3 no disco — então
 * o áudio crescia para sempre. Aqui os bytes de mensagens já retiradas de
 * circulação são recolhidos; o footprint fica limitado à janela de retenção em
 * vez de crescer sem fim.
 *
 * ── O que é seguro apagar ────────────────────────────────────────────────────
 * SÓ mensagens já SOFT-DELETADAS (`onlyTrashed`): fora da carência, invisíveis no
 * produto. Uma mensagem VIVA nunca entra aqui — apagar o áudio dela deixaria uma
 * bolha tocável sem som. A retenção efetiva do áudio = a da mensagem
 * (CHAT_ACCESS_DAYS + CHAT_GRACE_DAYS).
 *
 * ── A prova sob denúncia é INTOCÁVEL ─────────────────────────────────────────
 * Nunca apaga áudio de uma mensagem com denúncia ABERTA (`Report::OPEN_STATUSES`)
 * — a prova é retida até a denúncia fechar, exatamente como a foto/story efêmera.
 * Fechada a denúncia, a próxima rodada recolhe.
 *
 * A LINHA e o `audio_content_hash` FICAM (só `audio_path` vira null): o moderador
 * ainda vê "áudio recolhido" + o hash, como nas imagens. Idempotente: já sem
 * `audio_path`, não reprocessa.
 */
class PurgeExpiredChatAudio extends Command
{
    protected $signature = 'chat:purge-audio';

    protected $description = 'Recolhe os bytes de áudio de mensagens de voz fora da retenção (mantém a linha e o hash; nunca sob denúncia aberta).';

    public function handle(ChatAudioStore $store): int
    {
        // Ids de mensagens com denúncia ABERTA — a prova não pode ser recolhida.
        $openReported = Report::query()
            ->select('reportable_id')
            ->where('reportable_type', (new Message)->getMorphClass())
            ->whereIn('status', Report::OPEN_STATUSES);

        $deleted = 0;
        $failed = 0;

        Message::onlyTrashed()
            ->whereNotNull('audio_path')
            ->whereNotIn('id', $openReported)
            ->chunkById(200, function ($messages) use ($store, &$deleted, &$failed) {
                foreach ($messages as $message) {
                    try {
                        if ($store->exists($message->audio_path)) {
                            $store->delete($message->audio_path);
                        }
                        // Mantém a linha (soft-deletada) e o hash; some só o ponteiro
                        // dos bytes. forceFill porque audio_path está fora do fillable.
                        $message->forceFill(['audio_path' => null])->save();
                        $deleted++;
                    } catch (Throwable) {
                        $failed++;
                        Log::warning('chat:purge-audio falhou ao recolher bytes', ['message_id' => $message->id]);
                    }
                }
            });

        // Quantas seguem retidas por denúncia aberta — observabilidade, sem PII.
        $retainedUnderReport = Message::onlyTrashed()
            ->whereNotNull('audio_path')
            ->whereIn('id', $openReported)
            ->count();

        $counts = ['deleted' => $deleted, 'failed' => $failed, 'retained_under_report' => $retainedUnderReport];
        Log::info('chat:purge-audio', $counts);
        $this->info(sprintf('deleted=%d failed=%d retained_under_report=%d', $deleted, $failed, $retainedUnderReport));

        if ($failed > 0) {
            Log::warning('chat:purge-audio não conseguiu recolher todos os bytes', $counts);
        }

        return self::SUCCESS;
    }
}
