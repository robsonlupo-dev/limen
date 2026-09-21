<?php

namespace App\Http\Controllers\Web\Performer;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\User;
use App\Services\MemberNicknameService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Denúncia de APELIDO de membro pela performer (feat/nickname-report, Fase 4b).
 *
 * A performer vê o apelido público do membro (no lugar do "Fã #NNNN") e pode
 * denunciá-lo. A identificação é pela STRING do apelido — pública e única —,
 * exatamente como a remoção do moderador: nunca expõe o user_id do membro, e
 * funciona de qualquer superfície onde ela vê o apelido.
 *
 * Reusa o modelo Report (o denunciável é o MEMBRO). NÃO passa pelo report.store
 * genérico: aquele resolve por id, e aceitar apelido lá abriria enumeração de
 * contas (ver Report::resolveFromHandle/visibleTo, que fecham essa porta).
 *
 * Anti-oráculo: apelido inexistente, autodenúncia e repetição respondem TODAS a
 * mesma coisa ("recebida") — a performer nunca descobre se o apelido existe, de
 * quem é, ou se já foi denunciado.
 */
class NicknameReportController extends Controller
{
    /** Motivos aceitos para denúncia de apelido (subconjunto do enum de denúncia). */
    private const REASONS = ['impersonation', 'spam', 'other'];

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nickname' => ['required', 'string', 'max:20'],
            'reason' => ['required', 'string', 'in:'.implode(',', self::REASONS)],
            'details' => ['nullable', 'string', 'max:500'],
        ]);

        $received = 'Denúncia recebida. Nossa equipe vai analisar.';

        $member = User::where('nickname_normalized', MemberNicknameService::normalizeUnique($data['nickname']))
            ->where('role', 'consumer')
            ->whereNotNull('nickname')
            ->first();

        // Inexistente OU o próprio denunciante: resposta uniforme (anti-oráculo).
        if ($member === null || $member->id === $request->user()->id) {
            return back()->with('success', $received);
        }

        // Dedup: mesmo denunciante + mesmo membro + mesmo motivo na janela. O lock
        // fecha o duplo-submit, como no report.store genérico.
        $lockKey = sprintf('nick-report:%d:%d:%s', $request->user()->id, $member->id, $data['reason']);
        $lock = Cache::lock($lockKey, 10);

        if (! $lock->get()) {
            return back()->with('success', $received);
        }

        try {
            $already = Report::where('reporter_id', $request->user()->id)
                ->where('reportable_type', $member->getMorphClass())
                ->where('reportable_id', $member->getKey())
                ->where('reason', $data['reason'])
                ->where('created_at', '>=', now()->subHours(Report::DEDUP_WINDOW_HOURS))
                ->exists();

            if (! $already) {
                // Snapshot do apelido no `details`: o moderador vê O QUE foi
                // denunciado mesmo que o membro troque/limpe o apelido depois.
                $details = 'Apelido denunciado: "'.$data['nickname'].'"';
                if (! empty($data['details'])) {
                    $details .= "\n\n".$data['details'];
                }

                Report::open($request->user(), $member, $data['reason'], $details);
            }
        } finally {
            $lock->release();
        }

        return back()->with('success', $received);
    }
}
