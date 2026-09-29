<?php

namespace App\Services;

use App\Models\PerformerProfile;
use App\Models\Tip;
use App\Models\TipGoal;
use Illuminate\Support\Facades\DB;

/**
 * Metas de gorjeta (roadmap social, Onda 4 — §4.2). Dona única: definir, encerrar e ler
 * a meta ativa + o progresso passam só por aqui.
 *
 * "Uma ativa por performer": `set` ENCERRA a anterior antes de criar a nova (não há
 * UNIQUE dura — guarda histórico). O PROGRESSO é a soma das gorjetas recebidas DESDE o
 * `started_at` da meta (chave por performer_profile_id na tabela `tips`) — leitura
 * agregada, NÃO mexe em token nem no ledger. O total arrecadado são tokens de gorjeta
 * (a gorjeta já é 80/20; a meta é um número de mobilização, não uma nova cobrança).
 *
 * Anonimato: o progresso é só soma + alvo, nunca "quem deu" — inerentemente seguro.
 */
class TipGoalService
{
    /** Meta ativa da performer, ou null. */
    public function currentFor(PerformerProfile $profile): ?TipGoal
    {
        return TipGoal::where('performer_profile_id', $profile->id)
            ->active()
            ->latest('id')
            ->first();
    }

    /**
     * Define (substitui) a meta ativa. Encerra a anterior e cria a nova com o relógio
     * de progresso zerado (started_at = agora). O texto já veio validado (SafeProfileText).
     *
     * @param  array{title:string, target:int}  $data
     */
    public function set(PerformerProfile $profile, array $data): TipGoal
    {
        return DB::transaction(function () use ($profile, $data) {
            // Encerra qualquer meta ativa (uma por vez).
            TipGoal::where('performer_profile_id', $profile->id)
                ->active()
                ->update(['ended_at' => now()]);

            $goal = new TipGoal;
            $goal->forceFill([
                'performer_profile_id' => $profile->id,
                'title' => trim($data['title']),
                'target' => (int) $data['target'],
                'started_at' => now(),
                'ended_at' => null,
            ])->save();

            return $goal;
        });
    }

    /** Encerra a meta ativa (se houver). */
    public function end(PerformerProfile $profile): void
    {
        TipGoal::where('performer_profile_id', $profile->id)
            ->active()
            ->update(['ended_at' => now()]);
    }

    /**
     * Tokens de gorjeta arrecadados DESDE o início da meta. Soma o GROSS (`tips.amount`,
     * inteiro) por performer_profile_id — é o número que o público mobiliza rumo ao alvo.
     */
    public function raisedFor(TipGoal $goal): int
    {
        return (int) Tip::where('performer_profile_id', $goal->performer_profile_id)
            ->where('created_at', '>=', $goal->started_at)
            ->sum('amount');
    }

    /**
     * Payload público/leitura da meta ativa: título, alvo, arrecadado e % (cap 100).
     * `null` quando não há meta ativa. Sem nada de "quem" — só agregado.
     *
     * @return array{title:string, target:int, raised:int, pct:int}|null
     */
    public function publicPayload(PerformerProfile $profile, ?TipGoal $goal = null): ?array
    {
        $goal ??= $this->currentFor($profile);
        if ($goal === null) {
            return null;
        }

        $raised = $this->raisedFor($goal);
        $target = max(1, (int) $goal->target);

        return [
            'title' => $goal->title,
            'target' => (int) $goal->target,
            'raised' => $raised,
            'pct' => (int) min(100, (int) floor($raised * 100 / $target)),
        ];
    }
}
