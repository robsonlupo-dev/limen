<?php

namespace App\Services;

use App\Mail\AccountDeletionCancelledMail;
use App\Mail\AccountDeletionRequestedMail;
use App\Models\DeletionLog;
use App\Models\Follow;
use App\Models\IdentityVerification;
use App\Models\MemberPhoto;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\PerformerContent;
use App\Models\PerformerStory;
use App\Models\PerformerVoiceIntro;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Direito de eliminação (LGPD art. 18, VI) com carência de 30 dias.
 *
 * ------------------------------------------------------------------
 * O QUE "ELIMINAR" SIGNIFICA AQUI (decisão do PO, 20/07/2026)
 * ------------------------------------------------------------------
 * A LGPD não é um `TRUNCATE`. O art. 16, I ressalva expressamente a guarda
 * necessária ao cumprimento de obrigação legal ou regulatória, e o art. 18 §4
 * admite a conservação quando o dado é de terceiro. Este serviço opera em três
 * categorias, e não em duas:
 *
 *  1. APAGA de verdade — dado que só diz respeito ao titular e não tem lastro
 *     legal: documentos de KYC (linhas + arquivos cifrados no disco), follows,
 *     tokens de sessão/API, visitas de perfil e as fotos efêmeras (linhas,
 *     acessos e bytes — ver purgeMemberPhotos()).
 *
 *  2. ANONIMIZA POR DESVÍNCULO — dado financeiro e de dupla titularidade:
 *     token_ledger, payments, tips, payouts, subscriptions, conversations,
 *     chat_access, performer_interests. As linhas ficam; a identidade some
 *     junto com o `users` anonimizado. São três razões somadas:
 *       - o ledger é append-only por princípio (CLAUDE.md §2) e o saldo é
 *         DERIVADO dele — apagar linhas reescreve o saldo de quem ficou;
 *       - `tips`, `chat_access` e `performer_interests` têm FK
 *         `restrictOnDelete` PARA `token_ledger`: apagar o ledger do membro
 *         exigiria apagar a gorjeta, que é o lastro do CRÉDITO da performer —
 *         dinheiro de terceiro;
 *       - obrigação fiscal sobre a receita já reconhecida.
 *     Onde a linha carrega texto livre ou chave de pagamento, o campo é
 *     esfregado (`pix_key`, `description`) — o valor fica, a PII não.
 *
 *  3. PRESERVA INTACTO — audit_logs (obrigação legal de trilha, INCLUSIVE o
 *     `ip` do titular, que é PII e sobrevive de propósito: uma trilha sem
 *     origem não prova nada) e `reports`
 *     nas DUAS direções. Uma denúncia de conteúdo com menor é a prova de que a
 *     plataforma foi notificada; apagá-la porque o denunciado pediu exclusão
 *     daria ao infrator um botão para destruir a prova contra si.
 *
 * O soft-delete do `users` é o encerramento: nunca há DELETE físico da linha,
 * que é o que mantém as FKs `restrictOnDelete` de pé e a trilha legível.
 */
class DeletionService
{
    /** Carência de arrependimento, em dias. */
    public const GRACE_DAYS = 30;

    /** Validade do link de confirmação enviado por e-mail, em horas. */
    public const TOKEN_TTL_HOURS = 48;

    /**
     * Status de payout que travam a exclusão: há dinheiro da performer em
     * trânsito ou em revisão manual. Encerrar a conta agora deixaria o valor
     * órfão — sem titular para receber e sem quem reclamar.
     */
    public const BLOCKING_PAYOUT_STATUSES = ['pending', 'processing', 'needs_review'];

    /**
     * Agenda a exclusão e devolve o token em claro (só existe aqui e no e-mail;
     * o banco guarda o hash).
     *
     * Idempotente: pedir duas vezes não reinicia o relógio da LGPD nem
     * reagenda para mais longe — o prazo corre do PRIMEIRO pedido. Um novo
     * token é emitido, porque o titular pode simplesmente não ter recebido o
     * primeiro e-mail.
     */
    public function requestDeletion(User $user, string $reason = 'user_request'): string
    {
        $this->assertDeletable($user);

        $token = Str::random(64);

        DB::transaction(function () use ($user, $reason, $token) {
            // Relido sob lock: sem ele, dois POSTs concorrentes (o throttle
            // permite 5/min) leem os dois "ainda não pediu" e gravam DOIS
            // DeletionLog. A execução marca só o mais recente, e sobra um log
            // órfão de um usuário que não existe mais para limpá-lo.
            $locked = User::whereKey($user->id)->lockForUpdate()->first();
            $alreadyRequested = $locked?->deletion_requested_at !== null;

            if ($alreadyRequested) {
                $user->deletion_requested_at = $locked->deletion_requested_at;
                $user->deletion_scheduled_at = $locked->deletion_scheduled_at;
            }

            if (! $alreadyRequested) {
                $user->deletion_requested_at = now();
                $user->deletion_scheduled_at = now()->addDays(self::GRACE_DAYS);
            }

            $user->deletion_token_hash = hash('sha256', $token);
            $user->deletion_token_expires_at = now()->addHours(self::TOKEN_TTL_HOURS);
            $user->save();

            if (! $alreadyRequested) {
                DeletionLog::create([
                    'user_id' => $user->id,
                    'requested_at' => $user->deletion_requested_at,
                    'reason' => $reason,
                ]);
            }

            Audit::log('account.deletion_requested', $user, [
                'scheduled_at' => $user->deletion_scheduled_at?->toIso8601String(),
                'reason' => $reason,
            ]);
        });

        Mail::to($user->email)->queue(new AccountDeletionRequestedMail(
            scheduledAt: $user->deletion_scheduled_at,
            token: $token,
        ));

        return $token;
    }

    /**
     * Cancela o pedido enquanto a carência não venceu.
     *
     * Devolve false quando não há o que cancelar OU quando a exclusão já foi
     * executada. Este é o ponto sem volta do desenho: depois do
     * `executed_at`, os documentos de KYC já foram destruídos e o cancelamento
     * devolveria uma conta oca — pior que negar.
     */
    public function cancelDeletion(User $user): bool
    {
        if ($user->deletion_requested_at === null) {
            return false;
        }

        if ($this->executedLogFor($user) !== null || $user->trashed()) {
            return false;
        }

        $email = $user->email;

        DB::transaction(function () use ($user) {
            $user->deletion_requested_at = null;
            $user->deletion_scheduled_at = null;
            $user->deletion_confirmed_at = null;
            $user->deletion_token_hash = null;
            $user->deletion_token_expires_at = null;
            $user->save();

            // O log do pedido não executado sai: ele registrava uma intenção
            // que o titular desfez, e mantê-lo deixaria "fulano quis sumir"
            // gravado para sempre. Os executados nunca são tocados.
            DeletionLog::where('user_id', $user->id)->whereNull('executed_at')->delete();

            Audit::log('account.deletion_cancelled', $user);
        });

        // O cancelamento avisa por e-mail pelo mesmo motivo que o pedido avisa,
        // e é o lado que faltava: quem sequestra a sessão e CANCELA um pedido
        // legítimo deixa o titular achando que está saindo quando não está —
        // e sem este e-mail, nada na caixa dele o contradiz.
        Mail::to($email)->queue(new AccountDeletionCancelledMail);

        return true;
    }

    /**
     * Resolve um token de confirmação em claro para o usuário dono, ou null se
     * for inválido/expirado/já usado. Comparação por hash — o token em claro
     * nunca é armazenado nem logado.
     */
    public function userForToken(string $token): ?User
    {
        return User::query()
            ->whereNotNull('deletion_requested_at')
            ->where('deletion_token_hash', hash('sha256', $token))
            ->where('deletion_token_expires_at', '>', now())
            ->first();
    }

    /**
     * Consome o token (uso único) e marca a confirmação por e-mail. Não executa
     * nada: a execução é do job, no vencimento da carência.
     */
    public function confirmDeletion(User $user): bool
    {
        if ($user->deletion_requested_at === null) {
            return false;
        }

        DB::transaction(function () use ($user) {
            $user->deletion_confirmed_at = now();
            $user->deletion_token_hash = null;
            $user->deletion_token_expires_at = null;
            $user->save();

            Audit::log('account.deletion_confirmed', $user);
        });

        return true;
    }

    /**
     * Lança se a conta não pode ser encerrada agora. Chamado no pedido E de
     * novo na execução: 30 dias é tempo de sobra para um payout entrar na fila
     * depois de o pedido ter sido aceito.
     */
    public function assertDeletable(User $user): void
    {
        if ($blocked = $this->blockingPayoutCount($user)) {
            throw new RuntimeException(
                "Exclusão bloqueada: {$blocked} payout(s) em aberto.",
            );
        }

        if ($blocked = $this->blockingPaymentCount($user)) {
            throw new RuntimeException(
                "Exclusão bloqueada: {$blocked} pagamento(s) em aberto.",
            );
        }
    }

    public function blockingPayoutCount(User $user): int
    {
        if ($user->role !== 'performer') {
            return 0;
        }

        return Payout::where('performer_id', $user->id)
            ->whereIn('status', self::BLOCKING_PAYOUT_STATUSES)
            ->count();
    }

    /**
     * Cobranças PIX ainda abertas travam o encerramento. Se a cobrança liquidar
     * depois, o webhook idempotente credita tokens numa wallet sem titular —
     * dinheiro entra e não há a quem devolver.
     *
     * O corte de 7 dias existe para o bloqueio não virar deadlock: uma cobrança
     * que o titular abandonou e que o reconcile nunca marcou como 'expired'
     * seguraria a exclusão para sempre, e aí o bug de conformidade seria pior
     * que o risco financeiro. Um PIX vive horas, não uma semana.
     */
    public function blockingPaymentCount(User $user): int
    {
        return Payment::where('user_id', $user->id)
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subDays(7))
            ->count();
    }

    /**
     * Executa a eliminação. Irreversível.
     *
     * Tudo numa transação: um encerramento parcial — KYC destruído mas conta
     * ainda ativa e logável — é o pior estado possível, porque a UI diria que
     * está tudo bem enquanto os documentos já sumiram.
     */
    public function executeDeletion(User $user, string $reason = 'user_request'): DeletionLog
    {
        $this->assertDeletable($user);

        // Fora da transação de propósito: o storage não faz rollback. Os
        // caminhos são coletados agora e os bytes só são destruídos DEPOIS do
        // commit — se o banco falhar, os arquivos continuam lá e o job tenta
        // de novo amanhã. A ordem inversa apagaria documentos de uma conta que
        // permaneceu viva.
        $filePaths = $this->collectFilePaths($user);

        $log = DB::transaction(function () use ($user, $reason) {
            $summary = [];

            // Antes de qualquer coisa: estes passos precisam do e-mail REAL, que
            // anonymizeUser() destrói no fim.
            $summary['waitlist'] = $this->purgeWaitlist($user);
            $summary['password_reset_tokens'] = $this->purgePasswordResets($user);
            $summary['sessions'] = $this->purgeSessions($user);

            $summary['identity_verifications'] = $this->purgeKycRecords($user);
            $summary['age_verification_scrubbed'] = $this->scrubAgeVerification($user);
            $summary['follows'] = $this->purgeFollows($user);
            $summary['favorites'] = $this->purgeFavorites($user);
            $summary['favorites_received'] = $this->purgeFavoritesToOwnProfile($user);
            $summary['saved_searches'] = $this->purgeSavedSearches($user);
            $summary['member_notes'] = $this->purgeMemberNotes($user);
            $summary['member_notes_written'] = $this->purgeMemberNotesByPerformer($user);
            // CORAÇÃO (motor de engajamento do catálogo): os corações RECEBIDOS pelo
            // membro (por member_id) e os DADOS pela performer (por perfil), mais o
            // contador diário de mensagens dela. FKs restrictOnDelete não disparam
            // (soft-delete dos dois lados); DELETE explícito nos dois sentidos.
            $summary['performer_hearts_received'] = $this->purgePerformerHearts($user);
            $summary['performer_hearts_sent'] = $this->purgePerformerHeartsByPerformer($user);
            $summary['performer_message_quotas'] = $this->purgePerformerMessageQuotas($user);
            // Chamadas privadas 1:1 (Sprint 15): o histórico de sessões FEITAS pelo
            // membro (por member_id) e RECEBIDAS pela performer (por perfil). Sem
            // conteúdo/bytes — só metadados (preço, minutos, horário); nada a
            // preservar como prova. O ledger (spend_call/call_credit) FICA
            // (append-only, lastro fiscal), só desvinculado, como o spend_boost.
            $summary['call_sessions'] = $this->purgeCallSessions($user);
            $summary['call_sessions_received'] = $this->purgeCallSessionsToOwnProfile($user);
            // Group show (Sprint 15): a participação do MEMBRO em grupos de
            // performers VIVAS NÃO sai pelo cascade (a sessão group da performer
            // permanece) — varre por member_id explícito (item 11 do CLAUDE.md:
            // cascade não dispara porque `users` é soft-delete/anonimização). O
            // lado da performer sai por cascade quando a sessão group dela é
            // apagada em `purgeCallSessionsToOwnProfile`.
            $summary['call_session_participations'] = $this->purgeCallSessionParticipants($user);
            // Agendamentos de chamada (feat/scheduled-call-v1): as reservas FEITAS
            // pelo membro (por member_id) e RECEBIDAS pela performer (por perfil). A
            // FK cascade não dispara (users/perfil são soft-delete). Só metadados
            // (horário, depósito, status); o ledger (spend_call_reservation/refund/
            // noshow/credit) FICA (append-only), como as chamadas. O call_session_id
            // já vira null quando a call_session ligada é apagada acima (nullOnDelete).
            $summary['call_reservations'] = $this->purgeCallReservations($user);
            $summary['call_reservations_received'] = $this->purgeCallReservationsToOwnProfile($user);
            // Pedidos/concessões de acesso a fotos privadas FEITOS pelo membro
            // (Sprint 13). O lado da PERFORMER (grants apontando para as fotos
            // dela) sai por cascade quando `purgePerformerPhotos` faz DELETE real
            // das fotos — ver lá.
            $summary['photo_grants'] = $this->purgePhotoGrants($user);
            $summary['tips_scrubbed'] = $this->scrubTips($user);
            $summary['member_interests'] = $this->purgeMemberInterests($user);
            $summary['profile_visits'] = $this->purgeProfileVisits($user);
            $summary['profile_visits_received'] = $this->purgeVisitsToOwnProfile($user);
            // Visitas bidirecionais (A.0.4), sentido performer→membro: as
            // RECEBIDAS por este membro (o mapa de quem o olhou) e as FEITAS por
            // esta performer (o histórico de navegação dela por membros). Nenhuma
            // sai por cascade — os dois lados são soft-delete/anonimização.
            $summary['member_profile_visits_received'] = $this->purgeMemberProfileVisits($user);
            $summary['member_profile_visits_made'] = $this->purgeMemberProfileVisitsByPerformer($user);
            $summary['member_photos'] = $this->purgeMemberPhotos($user);
            $summary['member_photos_preserved'] = $this->preservedMemberPhotoCount($user);
            $summary['member_photo_access_received'] = $this->purgePhotoAccessToOwnProfile($user);
            $summary['story_views'] = $this->purgeStoryViews($user);
            $summary['story_views_received'] = $this->purgeStoryViewsToOwnProfile($user);
            $summary['performer_stories'] = $this->purgePerformerStories($user);
            $summary['performer_stories_preserved'] = $this->preservedStoryCount($user);
            $summary['performer_photos'] = $this->purgePerformerPhotos($user);
            // Conteúdo permanente pago (Sprint 14): desbloqueios FEITOS pelo membro
            // (por user_id) e as PEÇAS da performer (por perfil). Bytes saem em
            // deleteFiles(); peça com denúncia em aberto preserva a LINHA (hash =
            // prova), como o story. Ledger (spend_content/content_credit) fica.
            $summary['content_unlocks'] = $this->purgeContentUnlocks($user);
            $summary['performer_content'] = $this->purgePerformerContent($user);
            $summary['performer_content_preserved'] = $this->preservedContentCount($user);
            // Intro de voz (feat/voice-intro): uma linha por perfil. Bytes saem em
            // deleteFiles(); a linha some (DELETE real). Sem preservação por
            // denúncia — a moderação é PRÉ-publicação, não há fila de denúncia
            // apontando para intro de voz.
            $summary['performer_voice_intro'] = $this->purgePerformerVoiceIntro($user);
            $summary['content_hash_checks'] = $this->purgeContentHashChecks($user);
            $summary['performer_locations'] = $this->purgePerformerLocations($user);
            // Slugs antigos do redirect de rename (UAT fix): carregam o nome
            // artístico descartado, então saem no Hard Delete. A FK
            // `cascadeOnDelete` NÃO dispara — `anonymizePerformerProfile` só
            // soft-deleta/anonimiza o perfil (item 11 do CLAUDE.md).
            $summary['previous_slugs'] = $this->purgePreviousSlugs($user);
            $summary['messages_soft_deleted'] = $this->softDeleteMessages($user);
            $summary['performer_profile'] = $this->anonymizePerformerProfile($user);
            $summary['payouts_scrubbed'] = $this->scrubPayouts($user);
            $summary['payments_scrubbed'] = $this->scrubPayments($user);
            $summary['tokens_revoked'] = $user->tokens()->delete();
            $summary['otp_codes'] = $this->purgeOtpCodes($user);

            // Preservados de propósito — contados para a prova de conformidade.
            $summary['preserved'] = [
                'audit_logs' => $user->auditLogs()->count(),
                // Append-only e lastro jurídico do aceite do Contrato de
                // Performance (ver CLAUDE.md). IP e user-agent já entram como
                // HMAC, então não há PII crua a esfregar — a linha fica inteira.
                'document_acceptances' => DB::table('document_acceptances')
                    ->where('user_id', $user->id)->count(),
                'reports_filed' => DB::table('reports')->where('reporter_id', $user->id)->count(),
                'token_ledger' => $this->ledgerEntryCount($user),
                'payouts' => Payout::where('performer_id', $user->id)->count(),
            ];

            $this->anonymizeUser($user);

            $log = DeletionLog::where('user_id', $user->id)
                ->whereNull('executed_at')
                ->latest('id')
                ->first();

            if ($log === null) {
                $log = DeletionLog::create([
                    'user_id' => $user->id,
                    'requested_at' => $user->deletion_requested_at ?? now(),
                    'reason' => $reason,
                ]);
            }

            $log->update([
                'executed_at' => now(),
                'data_summary' => $summary,
            ]);

            Audit::log('account.deletion_executed', $user, [
                'deletion_log_id' => $log->id,
                'reason' => $reason,
            ]);

            return $log;
        });

        $this->deleteFiles($filePaths, $log->id);

        return $log;
    }

    // ------------------------------------------------------------------
    // Passos individuais
    // ------------------------------------------------------------------

    /**
     * Caminhos de arquivo a destruir: documentos de KYC (disco `kyc`, cifrados),
     * mídia do perfil (disco `local`) e as fotos efêmeras do titular (disco
     * `member_photos`, cifradas). Coletado antes do commit, apagado depois — ver
     * executeDeletion().
     *
     * @return array<int, array{disk: string, path: string}>
     */
    private function collectFilePaths(User $user): array
    {
        $paths = [];

        // `withTrashed()`: a foto sai por soft delete e o arquivo pode ter
        // sobrevivido a uma falha do GC. Encerramento de conta é a última
        // chance de recolher esses bytes — depois daqui não há linha para
        // ninguém varrer.
        foreach (MemberPhoto::withTrashed()->where('user_id', $user->id)->get() as $photo) {
            if ($path = $photo->path_encrypted) {
                $paths[] = ['disk' => MemberPhotoStore::DISK, 'path' => $path];
            }
        }

        // Stories publicados pelo titular (§ 2.6). `withTrashed()` pela mesma
        // razão das fotos: o GC soft-deleta a linha depois de confirmar que os
        // bytes saíram, mas uma rodada que falhou no meio deixa o arquivo de pé —
        // e este é o último varredor que ainda enxerga o caminho.
        //
        // Os bytes saem de TODO story, inclusive dos que terão a linha preservada
        // por denúncia: o que a evidência precisa é o hash, não o arquivo (§ 2.4,
        // parte 1) — "preserva evidência SEM preservar conteúdo".
        foreach ($this->performerStoriesOf($user) as $story) {
            if ($path = $story->media_path) {
                $paths[] = ['disk' => PerformerStoryStore::DISK, 'path' => $path];
            }
        }

        foreach ($user->identityVerifications as $verification) {
            foreach (['document_front_path', 'document_back_path', 'selfie_path'] as $column) {
                if ($path = $verification->{$column}) {
                    $paths[] = ['disk' => 'kyc', 'path' => $path];
                }
            }
        }

        $profile = $user->performerProfile;

        foreach (['avatar_path', 'cover_path'] as $column) {
            if ($profile && ($path = $profile->{$column})) {
                $paths[] = ['disk' => 'local', 'path' => $path];
            }
        }

        // Fotos da galeria do perfil (Sprint 10). Conteúdo PÚBLICO da própria
        // performer, sem cifra e sem TTL — some no encerramento como avatar/cover.
        // Uma direção só, ao contrário do favorito: foto é sempre da performer,
        // não existe "foto apontada para o perfil" vinda de terceiro. A FK
        // `cascadeOnDelete` NÃO dispara (o perfil sai por soft-delete), então
        // este é o último varredor que enxerga o caminho no disco.
        if ($profile) {
            foreach ($profile->photos as $photo) {
                if ($path = $photo->path) {
                    $paths[] = ['disk' => PerformerPhotoStore::DISK, 'path' => $path];
                }
            }

            // Conteúdo permanente pago (Sprint 14). Os BYTES saem de TODA peça,
            // inclusive das que terão a LINHA preservada por denúncia: a evidência
            // precisa do hash, não do arquivo — "preserva evidência SEM preservar
            // conteúdo", como o story. Este é o último varredor que vê o caminho.
            foreach (PerformerContent::where('performer_profile_id', $profile->id)->get() as $piece) {
                if ($path = $piece->path) {
                    $paths[] = ['disk' => ContentStore::DISK, 'path' => $path];
                }

                // Vídeo (Sprint 16): o POSTER é um arquivo à parte no mesmo disco —
                // sem isto ficaria órfão no hard delete, como a foto ficaria sem a
                // varredura acima. (O content_hash preservado é do MP4, não do poster.)
                if ($thumb = $piece->thumbnail_path) {
                    $paths[] = ['disk' => ContentStore::DISK, 'path' => $thumb];
                }
            }

            // Intro de voz (feat/voice-intro): áudio EM CLARO da própria performer,
            // some no encerramento como avatar/galeria/conteúdo. UMA por perfil; a
            // FK cascadeOnDelete NÃO dispara (o perfil sai por soft-delete —
            // item 11), então este é o último varredor que enxerga o caminho.
            foreach (PerformerVoiceIntro::where('performer_profile_id', $profile->id)->get() as $intro) {
                if ($path = $intro->path) {
                    $paths[] = ['disk' => VoiceIntroStore::DISK, 'path' => $path];
                }
            }
        }

        return $paths;
    }

    /**
     * Destrói os bytes, DEPOIS do commit. Best-effort por natureza (o storage
     * não faz rollback), mas nunca silencioso: os discos rodam com
     * `throw => false`, então uma permissão errada devolveria `false` e o
     * encerramento terminaria "com sucesso" deixando KYC ou o rosto do titular
     * no volume. O log é o único sinal que sobra — sem o caminho, que carrega o
     * id do titular (princípio 4).
     *
     * O log leva o `deletion_log_id`, e não o caminho: o caminho carrega o id do
     * titular (princípio 4), enquanto o id do log dá ao operador por onde puxar
     * o fio sem que a PII entre na trilha.
     *
     * @param  array<int, array{disk: string, path: string}>  $paths
     */
    private function deleteFiles(array $paths, int $logId): void
    {
        foreach ($paths as $file) {
            if (! Storage::disk($file['disk'])->delete($file['path'])) {
                Log::warning('deletion: arquivo do titular não pôde ser destruído', [
                    'disk' => $file['disk'],
                    'deletion_log_id' => $logId,
                ]);
            }
        }
    }

    /**
     * KYC é a PII mais sensível da base (CPF, RG, selfie) e não tem contraparte
     * nem valor fiscal: hard delete das linhas. Os bytes saem em deleteFiles().
     */
    private function purgeKycRecords(User $user): int
    {
        return IdentityVerification::where('user_id', $user->id)->delete();
    }

    /**
     * Follows saem de vez — é o grafo social do titular, e a performer só tem
     * dele um agregado. O contador precisa descer junto: ele alimenta o Piso de
     * Anonimato, e um piso inflado por seguidor fantasma abriria a lista de uma
     * performer que na verdade não chegou a 5.
     */
    private function purgeFollows(User $user): int
    {
        $profileIds = Follow::where('user_id', $user->id)
            ->pluck('performer_profile_id')
            ->all();

        $deleted = Follow::where('user_id', $user->id)->delete();

        foreach (array_unique($profileIds) as $profileId) {
            DB::table('performer_profiles')
                ->where('id', $profileId)
                ->where('followers_count', '>', 0)
                ->decrement('followers_count');
        }

        return $deleted;
    }

    /**
     * Favoritos do titular (Sprint 10): quais perfis ele salvou.
     *
     * Mesma família de `profile_visits` e dos interesses — mapa de interesse do
     * titular, sem valor fiscal e sem trilha legal. Some inteiro, e sem
     * contrapartida a acertar: ao contrário do follow logo acima, favorito não
     * alimenta contador nenhum, porque não existe contador de favoritos em
     * lugar nenhum (ver Favorite e PerformerProfile).
     *
     * A FK `cascadeOnDelete` de `favorites` NÃO dispara: `users` usa
     * SoftDeletes e anonymizeUser() não apaga a linha, então o banco não tem o
     * que cascatear (item 11 do CLAUDE.md).
     */
    private function purgeFavorites(User $user): int
    {
        return DB::table('favorites')->where('user_id', $user->id)->delete();
    }

    /**
     * O outro sentido: os favoritos APONTADOS para o perfil da performer que
     * encerra.
     *
     * É escolha de terceiros — membros que continuam ativos — pendurada num
     * perfil que deixou de existir. Não sai pelo purgeFavorites (aquele é por
     * `user_id`) nem pela FK, pelo mesmo motivo de purgeVisitsToOwnProfile: as
     * duas `cascadeOnDelete` de `favorites` nunca disparam, porque nenhum dos
     * dois lados sofre DELETE físico. E aqui não há sequer a retenção de 7 dias
     * do `visits:purge` para varrer depois — favorito não expira. Sem esta
     * linha, a órfã ficaria para sempre.
     *
     * Roda ANTES do anonymizePerformerProfile, enquanto a relação ainda resolve,
     * e consulta pela coluna em vez da relação carregada — mesma razão lá.
     */
    private function purgeFavoritesToOwnProfile(User $user): int
    {
        $profileId = DB::table('performer_profiles')->where('user_id', $user->id)->value('id');

        if ($profileId === null) {
            return 0;
        }

        return DB::table('favorites')->where('performer_profile_id', $profileId)->delete();
    }

    /**
     * Slugs abandonados por renames desta performer (UAT fix, fase 1). Some pelo
     * `performer_profile_id`, não pela FK: `anonymizePerformerProfile` só
     * soft-deleta o perfil, então o cascade não dispara (item 11 do CLAUDE.md).
     * Guarda o nome artístico slugificado que ela descartou — sem valor fiscal,
     * some com o resto. Roda enquanto o perfil ainda resolve pela coluna.
     */
    private function purgePreviousSlugs(User $user): int
    {
        $profileId = DB::table('performer_profiles')->where('user_id', $user->id)->value('id');

        if ($profileId === null) {
            return 0;
        }

        return DB::table('performer_profile_previous_slugs')
            ->where('performer_profile_id', $profileId)
            ->delete();
    }

    /**
     * As notas escritas SOBRE o titular (Sprint 11): o que outras performers
     * anotaram sobre ele.
     *
     * É opinião de terceiros pendurada no membro que encerra — PII sensível dele
     * (o dossiê que uma performer montou por FanAlias) sem valor fiscal nem
     * trilha legal. Some inteiro, da mesma família de `favorites` e
     * `profile_visits`. A FK `cascadeOnDelete` de `member_notes` NÃO dispara:
     * `anonymizeUser()` só soft-deleta o `users`, então o banco não tem o que
     * cascatear (item 11 do CLAUDE.md). Sem esta varredura as notas
     * sobreviveriam ao Hard Delete — e não há retenção que as varra depois.
     */
    private function purgeMemberNotes(User $user): int
    {
        return DB::table('member_notes')->where('user_id', $user->id)->delete();
    }

    /**
     * O outro sentido: as notas ESCRITAS pela performer que encerra sobre os
     * membros dela.
     *
     * Análogo exato de `purgeFavoritesToOwnProfile()` — dado que sai pelo
     * `performer_profile_id`, não pelo `user_id`, e que nenhuma das duas FKs
     * `cascadeOnDelete` de `member_notes` remove (os dois lados são
     * soft-delete/anonimização). Roda ANTES do `anonymizePerformerProfile`,
     * enquanto o perfil ainda resolve, e consulta pela coluna, não pela relação.
     */
    private function purgeMemberNotesByPerformer(User $user): int
    {
        $profileId = DB::table('performer_profiles')->where('user_id', $user->id)->value('id');

        if ($profileId === null) {
            return 0;
        }

        return DB::table('member_notes')->where('performer_profile_id', $profileId)->delete();
    }

    /**
     * CORAÇÃO — os corações RECEBIDOS pelo membro que encerra (motor de
     * engajamento do catálogo). DELETE real por `member_id`: a FK
     * `restrictOnDelete` de `performer_hearts` nunca dispara (os dois lados são
     * soft-delete/anonimização, item 11 do CLAUDE.md), e não há retenção que varra
     * depois. É o mapa de "quais performers me curtiram" — dado comportamental do
     * titular, sem valor fiscal/legal a preservar.
     */
    private function purgePerformerHearts(User $user): int
    {
        return DB::table('performer_hearts')->where('member_id', $user->id)->delete();
    }

    /**
     * O outro sentido: os corações que a PERFORMER que encerra deu a membros. Por
     * `performer_profile_id`, análogo a `purgeMemberNotesByPerformer` — roda ANTES
     * do `anonymizePerformerProfile`, enquanto o perfil ainda resolve, e consulta
     * pela coluna, não pela relação.
     */
    private function purgePerformerHeartsByPerformer(User $user): int
    {
        $profileId = DB::table('performer_profiles')->where('user_id', $user->id)->value('id');

        if ($profileId === null) {
            return 0;
        }

        return DB::table('performer_hearts')->where('performer_profile_id', $profileId)->delete();
    }

    /**
     * Contador diário de mensagens grátis da performer que encerra. Só metadados de
     * franquia (perfil, dia, contagem) — nada de membro. Por perfil, como acima.
     */
    private function purgePerformerMessageQuotas(User $user): int
    {
        $profileId = DB::table('performer_profiles')->where('user_id', $user->id)->value('id');

        if ($profileId === null) {
            return 0;
        }

        return DB::table('performer_message_quotas')->where('performer_profile_id', $profileId)->delete();
    }

    /**
     * Chamadas 1:1 que o MEMBRO fez (Sprint 15). DELETE real por `member_id`: a FK
     * `cascadeOnDelete` de `call_sessions` NÃO dispara porque `anonymizeUser()` só
     * soft-deleta o `users` (item 11 do CLAUDE.md). Só metadados de sessão — sem
     * bytes/hash a preservar. O ledger (spend_call) permanece (append-only).
     */
    private function purgeCallSessions(User $user): int
    {
        return DB::table('call_sessions')->where('member_id', $user->id)->delete();
    }

    /**
     * O outro sentido: as chamadas RECEBIDAS pelo perfil da performer que encerra.
     * Sai pelo `performer_profile_id` (a FK cascade também não dispara — perfil é
     * soft-delete). Roda ANTES do `anonymizePerformerProfile`, enquanto o perfil
     * ainda resolve. Mesma disciplina de `purgeMemberNotesByPerformer`.
     */
    private function purgeCallSessionsToOwnProfile(User $user): int
    {
        $profileId = DB::table('performer_profiles')->where('user_id', $user->id)->value('id');

        if ($profileId === null) {
            return 0;
        }

        return DB::table('call_sessions')->where('performer_profile_id', $profileId)->delete();
    }

    /**
     * Participações do MEMBRO em group shows (Sprint 15). DELETE real por
     * `member_id`: a participação num group de uma performer VIVA não sai pelo
     * cascade (a sessão group da performer permanece), então sem esta varredura o
     * `member_id` sobreviveria numa linha de outra pessoa. A FK cascade só remove
     * o lado da performer (quando a sessão group DELA é apagada acima). O ledger
     * (spend_call/call_credit) fica (append-only).
     */
    private function purgeCallSessionParticipants(User $user): int
    {
        return DB::table('call_session_participants')->where('member_id', $user->id)->delete();
    }

    /**
     * Agendamentos de chamada FEITOS pelo membro (feat/scheduled-call-v1). DELETE
     * real por `member_id`: a FK `cascadeOnDelete` não dispara (users é soft-delete/
     * anonimização — item 11 do CLAUDE.md). O ledger do depósito/refund/no-show
     * permanece (append-only). Mesma disciplina de purgeCallSessions.
     */
    private function purgeCallReservations(User $user): int
    {
        return DB::table('call_reservations')->where('member_id', $user->id)->delete();
    }

    /**
     * O outro sentido: as reservas RECEBIDAS pelo perfil da performer que encerra.
     * Sai por `performer_profile_id`, ANTES do anonymizePerformerProfile (enquanto
     * o perfil ainda resolve). Mesma disciplina de purgeCallSessionsToOwnProfile.
     */
    private function purgeCallReservationsToOwnProfile(User $user): int
    {
        $profileId = DB::table('performer_profiles')->where('user_id', $user->id)->value('id');

        if ($profileId === null) {
            return 0;
        }

        return DB::table('call_reservations')->where('performer_profile_id', $profileId)->delete();
    }

    /**
     * Interesses do membro (Sprint 9). DELETE real, dentro da transação.
     *
     * A FK cascadeOnDelete de `member_interest` NÃO dispara aqui: `users` usa
     * SoftDeletes, então a linha do usuário continua na tabela e o banco não
     * tem o que cascatear. Mesma armadilha do item 11 do CLAUDE.md para
     * profile_visits e das tags da performer logo abaixo — sem esta linha os
     * interesses sobreviveriam ao Hard Delete.
     *
     * Vai inteiro, sem esfregar nem preservar: é dado sensível de vida sexual
     * (LGPD art. 5º, II) — o mapa de desejo do titular, da mesma família do
     * `preferred_world` que anonymizeUser() já zera. Não tem valor fiscal nem
     * trilha legal. O `seeking`, que é a outra metade do mesmo formulário, sai
     * no scrub do usuário porque é coluna, não linha.
     */
    private function purgeMemberInterests(User $user): int
    {
        return $user->interests()->delete();
    }

    /**
     * Mensagens do titular somem da UI por soft-delete, como já faz a retenção
     * do chat (PurgeExpiredChatAccess). Hard delete apagaria metade de uma
     * conversa de duas pessoas e destruiria a trilha de abuso que a outra parte
     * pode precisar — inclusive contra o próprio titular que está saindo.
     */
    private function softDeleteMessages(User $user): int
    {
        return Message::where('sender_id', $user->id)->delete();
    }

    /**
     * O perfil público some (soft delete) e os campos livres são esfregados. Não
     * há hard delete possível aqui nem se quiséssemos: `tips`, `conversations` e
     * `performer_interests` apontam para ele com `restrictOnDelete`.
     */
    private function anonymizePerformerProfile(User $user): bool
    {
        $profile = $user->performerProfile;

        if ($profile === null) {
            return false;
        }

        $profile->forceFill([
            // O sufixo com id NÃO é cosmético: `stage_name` tem índice único que
            // cobre linhas soft-deleted (migration de 15/07). Um '[removido]'
            // literal deixaria só a PRIMEIRA performer do sistema ser excluída —
            // a segunda estouraria Duplicate entry, a transação faria rollback e
            // o job engoliria o erro como "skipped" todo dia, para sempre, com o
            // KYC intacto no disco e o prazo legal correndo.
            'stage_name' => '[removido] #'.$profile->id,
            // O slug é público e costuma ser o nome artístico: some junto, mas
            // continua único (a coluna tem índice único desde 15/07).
            'slug' => 'removido-'.$profile->id,
            'bio' => null,
            // "Sobre mim" (Sprint 9): auto-descrição do titular, mesma natureza
            // da bio e sem valor fiscal nem trilha legal. `looking_for` é texto
            // livre; os demais desenham um retrato dela.
            'languages' => null,
            'drinks' => null,
            'smokes' => null,
            'height_cm' => null,
            'looking_for' => null,
            // Localização (Sprint 9). `city` sai junto mesmo nunca tendo sido
            // pública: o Hard Delete apaga o que a titular deu, não só o que
            // estava à vista. Nenhum dos dois tem valor fiscal ou trilha legal.
            'state' => null,
            'city' => null,
            'avatar_path' => null,
            'cover_path' => null,
            'is_live' => false,
            // "Disponível para conversa" (Sprint 11): o carimbo é sinal de
            // presença, sem valor fiscal nem trilha legal — sai junto. Não
            // basta ele estar vencido (a janela de 4h passou): o Hard Delete
            // apaga o dado, não conta com a expiração da leitura.
            'available_for_chat_at' => null,
            // Boost pago (Sprint 11): o carimbo do destaque, mesma natureza do
            // `available_for_chat_at` acima — presença sem valor fiscal nem
            // legal. Sai junto, sem contar com a expiração da leitura. O débito
            // de tokens que pagou o boost FICA no ledger (append-only, lastro
            // fiscal), só desvinculado como o resto — não é este campo.
            'boosted_until' => null,
            'is_verified' => false,
        ])->save();

        // DELETE real, dentro da transação do expurgo. A FK cascadeOnDelete de
        // performer_tag NÃO dispara aqui: o `delete()` abaixo é soft (o model usa
        // SoftDeletes), então a linha do perfil continua na tabela e o banco não
        // tem o que cascatear. Mesma armadilha do item 11 do CLAUDE.md para
        // profile_visits. Sem esta linha as tags sobreviveriam ao Hard Delete.
        $profile->tags()->delete();

        $profile->delete();

        return true;
    }

    /**
     * Payout guarda a chave PIX — dado de pagamento do titular. O valor e o
     * `asaas_transfer_id` ficam (obrigação fiscal); a chave sai.
     */
    private function scrubPayouts(User $user): int
    {
        $scrubbed = 0;

        foreach (Payout::where('performer_id', $user->id)->get() as $payout) {
            $payout->forceFill([
                'pix_key' => '[removido]',
                'failure_reason' => $payout->failure_reason ? '[removido]' : null,
            ])->save();
            $scrubbed++;
        }

        return $scrubbed;
    }

    /**
     * O payload do PIX (QR e copia-e-cola) carrega dados do pagador e não tem
     * função depois que a cobrança fecha. Valor, status e id do provedor ficam.
     */
    private function scrubPayments(User $user): int
    {
        return Payment::where('user_id', $user->id)->update([
            'pix_qr_code' => null,
            'pix_copy_paste' => null,
        ]);
    }

    /**
     * `age_verifications` fica, sem o `cpf_hmac` (decisão do PO, 20/07/2026).
     *
     * A linha (user_id, method, verified_at) é a prova de que a plataforma
     * checou os 18+ — numa plataforma adulta é o artefato que uma fiscalização
     * pede primeiro, e o art. 16, I o cobre. O `cpf_hmac` é outra coisa: HMAC
     * de CPF é dado pessoal pseudonimizado, não anônimo, e um índice sobre ele
     * permite testar "este CPF já esteve aqui?" contra um CPF conhecido.
     *
     * O preço é explícito: sem o hmac, o mesmo CPF pode recadastrar. Manter o
     * guard exigiria manter o identificador de quem pediu para sumir.
     */
    private function scrubAgeVerification(User $user): bool
    {
        return DB::table('age_verifications')
            ->where('user_id', $user->id)
            ->whereNotNull('cpf_hmac')
            ->update(['cpf_hmac' => null]) > 0;
    }

    /**
     * A waitlist guarda `name` e `email` EM CLARO, numa tabela própria que não
     * tem FK com `users` — some do radar de qualquer varredura por user_id. Como
     * praticamente toda a base de lançamento entrou por ela, esquecer este passo
     * deixaria o titular "excluído" nominalmente identificável.
     */
    private function purgeWaitlist(User $user): int
    {
        // waitlist_email_log e waitlist_referrals apontam para a entrada com
        // cascadeOnDelete — apagar a entrada leva os dois junto.
        return DB::table('waitlist_entries')->where('email', $user->email)->delete();
    }

    /**
     * Códigos OTP de login (Sprint 11): material de autenticação efêmero, sem
     * valor fiscal nem legal. DELETE de verdade — a FK `cascadeOnDelete` NÃO
     * dispara porque `anonymizeUser()` só soft-deleta o `users` (item 11 do
     * CLAUDE.md), então sem esta varredura as linhas ficariam órfãs apontando
     * para uma conta anonimizada. São da mesma família das sessões e dos tokens
     * de API logo ao lado.
     */
    private function purgeOtpCodes(User $user): int
    {
        return DB::table('otp_codes')->where('user_id', $user->id)->delete();
    }

    /**
     * Buscas salvas do membro (Sprint 12): combinações de filtros, sem valor
     * fiscal nem legal, e só do lado do membro (não há "recebidas"). DELETE de
     * verdade — a FK `cascadeOnDelete` NÃO dispara porque `anonymizeUser()` só
     * soft-deleta o `users` (item 11 do CLAUDE.md), então sem esta varredura a
     * busca salva sobreviveria à conta anonimizada. Mesma família de `favorites`
     * e `otp_codes`.
     */
    private function purgeSavedSearches(User $user): int
    {
        return DB::table('saved_searches')->where('user_id', $user->id)->delete();
    }

    /**
     * Pedidos e concessões de acesso a fotos privadas FEITOS por este membro
     * (Sprint 13) — pendentes e aprovados, os dois estados vivem na mesma tabela.
     * DELETE de verdade por `user_id`: a FK `cascadeOnDelete` sobre `users` NÃO
     * dispara porque `anonymizeUser()` só soft-deleta a linha (item 11), então sem
     * esta varredura o mapa "este membro pediu foto de fulana" sobreviveria à
     * conta. Mesma família de `favorites`/`saved_searches`/`otp_codes`.
     *
     * O outro sentido (grants apontando para as fotos DELA quando a PERFORMER
     * encerra) NÃO precisa de varredura própria: `purgePerformerPhotos` faz DELETE
     * real das `performer_photos`, e aí o cascade de `photo_grants.performer_photo_id`
     * dispara de fato — ao contrário do caso soft-delete do item 11.
     */
    private function purgePhotoGrants(User $user): int
    {
        return DB::table('photo_grants')->where('user_id', $user->id)->delete();
    }

    /** A PK de password_reset_tokens É o e-mail: sem este passo, ele sobrevive. */
    private function purgePasswordResets(User $user): int
    {
        return DB::table('password_reset_tokens')->where('email', $user->email)->delete();
    }

    /**
     * Sessões guardam ip_address e user_agent, e ainda são sessões VIVAS: apagar
     * aqui derruba quem estiver logado na conta no instante do encerramento.
     */
    private function purgeSessions(User $user): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        return DB::table('sessions')->where('user_id', $user->id)->delete();
    }

    /**
     * `tips.message` é texto livre escrito pelo titular e fica visível no painel
     * da performer para sempre. O valor da gorjeta é fiscal e permanece; o
     * recado, não.
     */
    private function scrubTips(User $user): int
    {
        return DB::table('tips')
            ->where('consumer_id', $user->id)
            ->whereNotNull('message')
            ->update(['message' => null]);
    }

    /**
     * Histórico de navegação do titular: quais perfis ele visitou e quando.
     * Sem valor fiscal e sem trilha legal — hard delete, como os registros de
     * KYC. Não há nada a preservar aqui, e manter seria guardar o mapa de
     * interesses de uma conta encerrada.
     */
    private function purgeProfileVisits(User $user): int
    {
        return DB::table('profile_visits')->where('visitor_id', $user->id)->delete();
    }

    /**
     * O outro lado: as visitas RECEBIDAS pelo perfil da performer que encerra.
     *
     * É PII de terceiros — o histórico de navegação de membros que continuam
     * ativos — pendurada num perfil que deixou de existir. Não sai pelo
     * purgeProfileVisits (aquele é por `visitor_id`) e não sai pela FK: as duas
     * `cascadeOnDelete` de `profile_visits` NUNCA disparam, porque nem o usuário
     * nem o perfil sofrem DELETE físico — os dois são soft-delete. Sem esta
     * varredura o dado só sumiria pela retenção de 7 dias do `visits:purge`.
     *
     * Roda ANTES do anonymizePerformerProfile, enquanto a relação ainda resolve.
     * Consulta pela coluna, não pela relação carregada, para não depender do
     * estado do cache de relações numa re-execução do job.
     */
    private function purgeVisitsToOwnProfile(User $user): int
    {
        $profileId = DB::table('performer_profiles')->where('user_id', $user->id)->value('id');

        if ($profileId === null) {
            return 0;
        }

        return DB::table('profile_visits')->where('performer_profile_id', $profileId)->delete();
    }

    /**
     * Visitas bidirecionais (A.0.4), sentido performer→membro: as RECEBIDAS por
     * este membro — o mapa de quais performers olharam o perfil dele.
     *
     * É a lista que alimenta a tela "quem visitou seu perfil" do titular. Como o
     * `member_profile_visits` só é populado no sentido performer→membro, para um
     * membro há só o lado RECEBIDO aqui (o lado FEITO é sempre vazio — membro não
     * visita membro). Não sai pela FK `cascadeOnDelete` porque `anonymizeUser()`
     * é soft-delete (item 11 do CLAUDE.md), e a retenção de 7 dias só varreria
     * depois — o Hard Delete pede que suma agora.
     */
    private function purgeMemberProfileVisits(User $user): int
    {
        return DB::table('member_profile_visits')->where('member_id', $user->id)->delete();
    }

    /**
     * O outro sentido: as visitas que a PERFORMER que encerra FEZ a perfis de
     * membros — o histórico de navegação dela pelo catálogo de membros.
     *
     * É PII de terceiros correlacionável (quais membros esta performer olhou, e
     * quando) pendurada num perfil que deixou de existir. Análogo exato de
     * purgeVisitsToOwnProfile(): não sai por purgeMemberProfileVisits (aquele é
     * por `member_id`) nem pela FK (soft-delete dos dois lados). Consulta pela
     * coluna, não pela relação, para não depender do cache de relações numa
     * re-execução do job — e roda enquanto o perfil ainda resolve.
     */
    private function purgeMemberProfileVisitsByPerformer(User $user): int
    {
        $profileId = DB::table('performer_profiles')->where('user_id', $user->id)->value('id');

        if ($profileId === null) {
            return 0;
        }

        return DB::table('member_profile_visits')->where('performer_profile_id', $profileId)->delete();
    }

    /**
     * As fotos efêmeras do titular: linhas E acessos, em DELETE de verdade.
     *
     * É a PII mais crua que a plataforma guarda depois do KYC — o rosto —, com
     * um agravante que o KYC não tem: a foto já foi MOSTRADA a terceiros, e a
     * tabela de acessos é o mapa de para quem. Nada disso tem contraparte
     * financeira nem lastro legal, então é a categoria 1 do serviço (apaga de
     * verdade), como `profile_visits` e os registros de KYC.
     *
     * `withTrashed()` e `forceDelete()` porque o soft delete aqui não basta: a
     * linha carrega `user_id`, tamanho e horário do envio — "o membro X mandou
     * 43 fotos, nestes horários" —, e o Hard Delete é justamente o pedido para
     * que isso não exista mais. Os BYTES saem em deleteFiles(), depois do commit.
     *
     * Não sai pela FK: `cascadeOnDelete` nunca dispara, porque `anonymizeUser()`
     * não apaga a linha do `users` (item 11 do CLAUDE.md, o mesmo motivo de
     * purgeProfileVisits). Sem esta varredura, o rosto do titular continuaria no
     * disco até o TTL — até 7 dias depois do encerramento — e servível a quem já
     * tinha acesso concedido.
     *
     * ── EXCEÇÃO: a foto DENUNCIADA mantém a linha (30/07/2026) ──────────────
     * Mesma regra que este serviço já aplica a `reports` e aos stories, pela
     * mesma frase: *"apagá-la porque o denunciado pediu exclusão daria ao
     * infrator um botão para destruir a prova contra si"*. Encerrar a conta é a
     * versão mais poderosa desse botão — e era o último caminho que continuava
     * aberto depois de o revoke e o GC terem sido fechados.
     *
     * **Os BYTES saem mesmo assim, de toda foto**, inclusive das preservadas
     * (`collectFilePaths()` as recolhe todas). É a diferença que importa em
     * relação à retenção do revoke: lá o titular ainda existe e a revisão pode
     * precisar olhar o conteúdo; aqui ele exerceu o direito de exclusão, e
     * guardar o rosto de quem pediu para sumir seria trocar um problema por
     * outro. O que sobrevive é o `content_hash` + os carimbos — **prova sem
     * conteúdo**, exatamente como no story.
     *
     * A linha preservada sai SOFT-deletada e com `expires_at` no passado. As duas
     * coisas juntas fazem o GC ignorá-la para sempre pelo ramo que ele já tem
     * (`trashed()` e sem arquivo no disco = trabalho concluído): sem isso, a
     * primeira rodada depois de a denúncia ser concluída tentaria apagar bytes
     * que já não existem, e `MemberPhotoStore::delete()` LANÇA nesse caso — a
     * foto entraria em `failed` a cada hora, para sempre.
     *
     * Os ACESSOS saem sempre, também das preservadas: são PII de terceiros (o
     * mapa de para quem o rosto foi mostrado) e não são a prova de nada — a
     * denúncia guarda quem denunciou.
     */
    private function purgeMemberPhotos(User $user): int
    {
        $photoIds = MemberPhoto::withTrashed()
            ->where('user_id', $user->id)
            ->pluck('id');

        if ($photoIds->isEmpty()) {
            return 0;
        }

        DB::table('member_photo_access')->whereIn('member_photo_id', $photoIds)->delete();

        $reportedIds = DB::table('reports')
            ->where('reportable_type', (new MemberPhoto)->getMorphClass())
            ->whereIn('reportable_id', $photoIds)
            ->pluck('reportable_id')
            ->all();

        if ($reportedIds !== []) {
            // `update()` cru e não os models: `deleted_at` e `expires_at` estão
            // fora do `$fillable`, e a varredura não precisa instanciar nada.
            MemberPhoto::withTrashed()
                ->whereIn('id', $reportedIds)
                ->update(['deleted_at' => now(), 'expires_at' => now()]);
        }

        $deletableIds = array_values(array_diff($photoIds->all(), $reportedIds));

        if ($deletableIds === []) {
            return 0;
        }

        return MemberPhoto::withTrashed()->whereIn('id', $deletableIds)->forceDelete();
    }

    /**
     * Quantas fotos do titular tiveram a LINHA preservada por denúncia.
     *
     * Contada para a prova de conformidade, como `preservedStoryCount()`: o
     * resumo do encerramento precisa dizer o que NÃO foi apagado, senão a única
     * leitura possível de "member_photos: 3" seria "apagou tudo".
     */
    private function preservedMemberPhotoCount(User $user): int
    {
        $photoIds = MemberPhoto::withTrashed()
            ->where('user_id', $user->id)
            ->pluck('id');

        if ($photoIds->isEmpty()) {
            return 0;
        }

        return DB::table('reports')
            ->where('reportable_type', (new MemberPhoto)->getMorphClass())
            ->whereIn('reportable_id', $photoIds)
            ->distinct()
            ->count('reportable_id');
    }

    /**
     * O outro lado: os acessos concedidos AO perfil da performer que encerra.
     *
     * PII de terceiros — quais membros ainda ativos mostraram o rosto para ela —
     * pendurada num perfil que deixou de existir. Não sai por purgeMemberPhotos
     * (aquele é por `user_id` do titular da foto) e não sai pela FK, pelo mesmo
     * motivo de sempre: nem o usuário nem o perfil sofrem DELETE físico. É o
     * análogo exato de purgeVisitsToOwnProfile().
     *
     * Só as LINHAS DE ACESSO: as fotos são de outra pessoa, continuam vivas para
     * o dono e para as outras performers, e seus bytes seguem o TTL normal.
     *
     * Roda ANTES do anonymizePerformerProfile, enquanto a relação ainda resolve.
     */
    private function purgePhotoAccessToOwnProfile(User $user): int
    {
        $profileId = DB::table('performer_profiles')->where('user_id', $user->id)->value('id');

        if ($profileId === null) {
            return 0;
        }

        return DB::table('member_photo_access')->where('performer_profile_id', $profileId)->delete();
    }

    /**
     * As views que o TITULAR gerou: quais stories ele abriu (§ 2.6).
     *
     * Estruturalmente idêntica a `profile_visits` — mapa de interesses
     * membro→performer — e tratada igual: hard delete, sem nada a preservar. Não
     * sai pela FK: `anonymizeUser()` não apaga a linha do `users`, então o
     * `cascadeOnDelete` de `story_views` NUNCA dispara (item 11 do CLAUDE.md).
     * Sem esta varredura, o dado só sumiria quando o story vencesse.
     */
    private function purgeStoryViews(User $user): int
    {
        return DB::table('story_views')->where('user_id', $user->id)->delete();
    }

    /**
     * O outro lado: as views RECEBIDAS pelos stories da performer que encerra.
     *
     * PII de terceiros — quais membros ainda ativos assistiram ao conteúdo dela —
     * pendurada num perfil que deixou de existir. Não sai por `purgeStoryViews()`
     * (aquele é por `user_id` do espectador) nem pela FK, pelo motivo de sempre:
     * nem o usuário nem o perfil sofrem DELETE físico. É o análogo exato de
     * `purgeVisitsToOwnProfile()`.
     *
     * Apaga as views inclusive dos stories que serão PRESERVADOS por denúncia
     * (ver `purgePerformerStories()`): a evidência que a moderação precisa é o
     * conteúdo publicado e o hash dele, nunca quem o assistiu. Guardar a
     * audiência junto seria preservar PII de terceiros a pretexto da prova.
     *
     * Roda ANTES do anonymizePerformerProfile, enquanto a relação ainda resolve.
     */
    private function purgeStoryViewsToOwnProfile(User $user): int
    {
        $storyIds = $this->storyIdsOf($user);

        if ($storyIds === []) {
            return 0;
        }

        return DB::table('story_views')->whereIn('performer_story_id', $storyIds)->delete();
    }

    /**
     * Os stories da performer que encerra: linhas e — em `deleteFiles()`, depois
     * do commit — os bytes.
     *
     * ── O que é apagado, e o que NÃO é ──────────────────────────────────────
     * Os BYTES saem sempre, de todo story: é conteúdo do titular num disco
     * nosso, e o encerramento é o pedido para que não exista mais. A LINHA sai
     * junto, EXCETO quando aquele story tem denúncia — e essa exceção é a mesma
     * regra que este serviço já aplica a `reports`, pela mesma frase: *"apagá-la
     * porque o denunciado pediu exclusão daria ao infrator um botão para destruir
     * a prova contra si"*. Encerrar a conta é a versão mais poderosa desse botão.
     *
     * O que sobrevive na linha preservada é o `content_hash` (§ 2.4, parte 1) e o
     * carimbo de quando foi publicado — **prova sem conteúdo**, que é exatamente
     * a resposta que a decisão dá para a pergunta original. Sem isso, a denúncia
     * preservada apontaria para um id que já não existe em lugar nenhum, e o
     * matching contra hash conhecido (o que de fato bloqueia o re-upload) morreria
     * com a conta do infrator.
     *
     * A linha preservada não é PII de terceiros: carrega o id do perfil — que o
     * `anonymizePerformerProfile()` esvazia logo em seguida —, o nível, os
     * carimbos e o hash. A audiência dela sai junto, em
     * `purgeStoryViewsToOwnProfile()`.
     *
     * `withTrashed()` porque o soft delete do GC não basta: a linha e o arquivo
     * podem ter sobrevivido a uma falha de rodada, e este é o último varredor.
     */
    private function purgePerformerStories(User $user): int
    {
        $storyIds = $this->storyIdsOf($user);

        if ($storyIds === []) {
            return 0;
        }

        $reportedIds = DB::table('reports')
            ->where('reportable_type', (new PerformerStory)->getMorphClass())
            ->whereIn('reportable_id', $storyIds)
            ->pluck('reportable_id')
            ->all();

        $deletableIds = array_values(array_diff($storyIds, $reportedIds));

        if ($deletableIds === []) {
            return 0;
        }

        return PerformerStory::withTrashed()->whereIn('id', $deletableIds)->forceDelete();
    }

    /**
     * A galeria de fotos do perfil da performer que encerra (Sprint 10): as
     * LINHAS. Os BYTES saem em `deleteFiles()`, depois do commit.
     *
     * Uma direção só — foto é sempre da própria performer, e não há a
     * contraparte "foto apontada para o perfil" que existe no favorito e nas
     * visitas. Sem denúncia a preservar (foto de perfil não é moderada por hash
     * na v1), então é DELETE de verdade e sem contador de preservados.
     *
     * DELETE cru pela COLUNA, dentro da transação: a FK `cascadeOnDelete` NÃO
     * dispara (o perfil sai por soft-delete/anonimização — item 11 do CLAUDE.md),
     * e consultar pela coluna não depende do cache de relações numa re-execução
     * do job. Sem esta varredura, as fotos sobreviveriam ao Hard Delete, sem
     * retenção que as varra depois — foto de perfil não expira.
     */
    private function purgePerformerPhotos(User $user): int
    {
        $profileId = DB::table('performer_profiles')->where('user_id', $user->id)->value('id');

        if ($profileId === null) {
            return 0;
        }

        // DELETE real (não soft): por ser real, o cascade de
        // `photo_grants.performer_photo_id → performer_photos` DISPARA aqui e leva
        // junto todos os pedidos/concessões apontados para estas fotos (Sprint 13).
        // É a exceção do item 11 — o cascade só falha quando o PAI é soft-deletado,
        // e aqui a foto some de verdade. Os grants FEITOS pelo membro (outro lado)
        // saem por `purgePhotoGrants`, por `user_id`.
        return DB::table('performer_photos')->where('performer_profile_id', $profileId)->delete();
    }

    /**
     * Desbloqueios de conteúdo FEITOS pelo membro que encerra (Sprint 14), por
     * user_id. O outro lado (desbloqueios apontados para as peças DELA) sai por
     * cascade quando `purgePerformerContent` faz DELETE real das peças. O ledger
     * (spend_content) permanece — append-only, lastro fiscal.
     */
    private function purgeContentUnlocks(User $user): int
    {
        return DB::table('content_unlocks')->where('user_id', $user->id)->delete();
    }

    /**
     * Trilha de verificação de hash do upload (anti-CSAM). Apaga só as linhas de
     * ROTINA (matched=false) — trilha de uploads da conta, sem valor após o
     * encerramento, na disciplina de favorites/otp_codes. As linhas `matched=true`
     * FICAM (com o user_id): são evidência de CSAM, cuja retenção é dever legal —
     * a mesma lógica do story/foto denunciados, que sobrevivem ao encerramento.
     * A FK é nullOnDelete, mas anonymizeUser só soft-deleta o users, então não
     * dispara: a varredura é explícita.
     */
    private function purgeContentHashChecks(User $user): int
    {
        return DB::table('content_hash_checks')
            ->where('user_id', $user->id)
            ->where('matched', false)
            ->delete();
    }

    /**
     * As PEÇAS de conteúdo permanente da performer que encerra (Sprint 14). Peça
     * SEM denúncia em aberto: DELETE real (cascade leva content_unlocks). Peça COM
     * denúncia: a LINHA é PRESERVADA (hash = prova), os bytes saem em deleteFiles()
     * como todo mundo. Mesma disciplina do `purgePerformerStories`.
     */
    private function purgePerformerContent(User $user): int
    {
        $profileId = DB::table('performer_profiles')->where('user_id', $user->id)->value('id');

        if ($profileId === null) {
            return 0;
        }

        $contentIds = DB::table('performer_content')->where('performer_profile_id', $profileId)->pluck('id')->all();

        if ($contentIds === []) {
            return 0;
        }

        $reportedIds = DB::table('reports')
            ->where('reportable_type', (new PerformerContent)->getMorphClass())
            ->whereIn('reportable_id', $contentIds)
            ->pluck('reportable_id')
            ->all();

        $deletableIds = array_values(array_diff($contentIds, $reportedIds));

        if ($deletableIds === []) {
            return 0;
        }

        // DELETE real: o cascade de `content_unlocks.performer_content_id` DISPARA.
        return DB::table('performer_content')->whereIn('id', $deletableIds)->delete();
    }

    /**
     * Intro de voz (feat/voice-intro): uma linha por perfil, áudio da própria
     * performer. DELETE real das linhas — a FK cascadeOnDelete NÃO dispara porque
     * `anonymizePerformerProfile` só soft-deleta o perfil (item 11). Bytes já
     * saíram em deleteFiles(). Sem preservação por denúncia (moderação é
     * pré-publicação).
     */
    private function purgePerformerVoiceIntro(User $user): int
    {
        $profileId = DB::table('performer_profiles')->where('user_id', $user->id)->value('id');

        if ($profileId === null) {
            return 0;
        }

        return DB::table('performer_voice_intros')->where('performer_profile_id', $profileId)->delete();
    }

    /** Peças preservadas por denúncia em aberto — contadas para a prova. */
    private function preservedContentCount(User $user): int
    {
        $profileId = DB::table('performer_profiles')->where('user_id', $user->id)->value('id');

        if ($profileId === null) {
            return 0;
        }

        $contentIds = DB::table('performer_content')->where('performer_profile_id', $profileId)->pluck('id')->all();

        if ($contentIds === []) {
            return 0;
        }

        return DB::table('reports')
            ->where('reportable_type', (new PerformerContent)->getMorphClass())
            ->whereIn('reportable_id', $contentIds)
            ->distinct()
            ->count('reportable_id');
    }

    /**
     * Localizações da performer (Sprint 13). DELETE real por `performer_profile_id`:
     * a FK `cascadeOnDelete` NÃO dispara porque o perfil sai por
     * soft-delete/anonimização (item 11 do CLAUDE.md), então sem esta varredura as
     * linhas — `city` interno incluído — sobreviveriam ao encerramento. Uma
     * direção só, como as fotos da galeria: localização é sempre da performer, não
     * existe "localização apontada para o perfil" vinda de terceiro. As colunas
     * cache `state`/`city` do próprio perfil já são zeradas por
     * anonymizePerformerProfile().
     */
    private function purgePerformerLocations(User $user): int
    {
        $profileId = DB::table('performer_profiles')->where('user_id', $user->id)->value('id');

        if ($profileId === null) {
            return 0;
        }

        return DB::table('performer_locations')->where('performer_profile_id', $profileId)->delete();
    }

    /**
     * Os stories do titular como MODELS — o que `collectFilePaths()` precisa para
     * ler `media_path`. Separado de `storyIdsOf()` porque aquele roda dentro da
     * transação e só precisa dos ids.
     *
     * @return Collection<int, PerformerStory>
     */
    private function performerStoriesOf(User $user)
    {
        $profileId = DB::table('performer_profiles')->where('user_id', $user->id)->value('id');

        if ($profileId === null) {
            return PerformerStory::withTrashed()->whereRaw('1 = 0')->get();
        }

        return PerformerStory::withTrashed()
            ->where('performer_profile_id', $profileId)
            ->get();
    }

    /** Quantas linhas de story ficaram de pé como evidência. Ver acima. */
    private function preservedStoryCount(User $user): int
    {
        $storyIds = $this->storyIdsOf($user);

        if ($storyIds === []) {
            return 0;
        }

        return PerformerStory::withTrashed()->whereIn('id', $storyIds)->count();
    }

    /**
     * Ids de todo story publicado por este usuário, vivos ou soft-deletados.
     *
     * Consulta pela COLUNA e não pela relação, para não depender do cache de
     * relações numa re-execução do job — mesma disciplina de
     * `purgeVisitsToOwnProfile()`.
     *
     * @return array<int, int>
     */
    private function storyIdsOf(User $user): array
    {
        $profileId = DB::table('performer_profiles')->where('user_id', $user->id)->value('id');

        if ($profileId === null) {
            return [];
        }

        return PerformerStory::withTrashed()
            ->where('performer_profile_id', $profileId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function ledgerEntryCount(User $user): int
    {
        $walletId = DB::table('token_wallets')->where('user_id', $user->id)->value('id');

        if ($walletId === null) {
            return 0;
        }

        // `description` é texto livre gravado pelos serviços e pode carregar o
        // nome artístico da contraparte. O valor e o balance_after — que é o que
        // torna o ledger auditável — ficam intocados.
        DB::table('token_ledger')
            ->where('wallet_id', $walletId)
            ->whereNotNull('description')
            ->update(['description' => null]);

        return DB::table('token_ledger')->where('wallet_id', $walletId)->count();
    }

    /**
     * Anonimiza o `users` e encerra por soft delete.
     *
     * O e-mail vira um hash determinístico por id, e não um `null`: a coluna é
     * única e NOT NULL, e um placeholder fixo colidiria no segundo
     * encerramento. Ele não é reversível para o endereço original.
     */
    private function anonymizeUser(User $user): void
    {
        $user->forceFill([
            'name' => '[removido]',
            'email' => 'deleted-'.hash('sha256', $user->id.'|'.config('app.key')).'@deleted.invalid',
            'email_verified_at' => null,
            // Senha aleatória e descartada: a conta fica inautenticável mesmo se
            // o soft delete for revertido por engano no banco.
            'password' => Hash::make(Str::random(64)),
            'remember_token' => null,
            'phone' => null,
            'phone_verified_at' => null,
            'birthdate' => null,
            'age_verified_at' => null,
            'asaas_customer_id' => null,
            // preferred_world é dado sensível de vida sexual (LGPD art. 5º, II) —
            // 'mulheres'/'homens'/'casais'/'trans' diz por quem o titular se
            // interessa. Sai junto, e com ele as preferências que só fazem
            // sentido enquanto existe alguém para preferir.
            'preferred_world' => null,
            // "O que estou buscando" (Sprint 9). Texto livre sobre o que o
            // titular procura — mesma natureza sensível do preferred_world logo
            // acima, e sem lastro fiscal nem legal. A outra metade do mesmo
            // formulário (os interesses) sai em purgeMemberInterests().
            'seeking' => null,
            // Estilo de Vida (Sprint 10). Auto-declaração patrimonial do
            // titular, da mesma família do `seeking` logo acima — e com um
            // agravante que o `seeking` não tem: esta é a única daquela tela que
            // TERCEIRO já viu. Mantê-la deixaria na linha encerrada um retrato
            // ("era Patrono") sem lastro fiscal nem legal, e ainda alcançável
            // por quem cruzasse listas antigas de performers. Sai junto, pelo
            // mesmo motivo dos perks de privacidade logo abaixo.
            //
            // E sai DE VERDADE: o evento `member_lifestyle_tier_updated` grava
            // só o booleano `disclosed`, nunca o slug. Se ele gravasse o valor,
            // este scrub seria cosmético — `audit_logs` é preservado intacto
            // (§ 3 acima), então a faixa sobreviveria ali, com o IP ao lado.
            // Quem for enriquecer aquele evento tem esta linha como motivo.
            'lifestyle_tier' => null,
            // Digest do IP de cadastro: é o que permite dizer "esta conta veio
            // do mesmo IP que aquela". Serve à detecção de sybil enquanto a
            // conta existe; depois do encerramento é só um identificador de
            // quem pediu para sumir.
            'registration_ip_hash' => null,
            'discrete_mode' => false,
            'interests_opt_out' => false,
            // Perks de privacidade: voltam ao lado PÚBLICO, como o discrete_mode
            // ao lado. Mantê-los deixava na linha encerrada o atestado de que a
            // pessoa era assinante Black/FC e quais escolhas de privacidade fez —
            // sem lastro fiscal nem legal que justifique guardar.
            //
            // Valor explícito em vez de null (que seria "nunca escolheu"): assim
            // effective() devolve o lado público sem depender de resolver o
            // Círculo de uma conta encerrada. Note que read_receipts_enabled é o
            // invertido dos três — público aqui é `true`.
            'ghost_mode' => false,
            'invisible_status' => false,
            'read_receipts_enabled' => true,
            // `status` NÃO é tocado: 'banned' é vocabulário de moderação e
            // marcar aqui contaminaria as métricas de abuso com quem só pediu
            // para sair. `deleted_at` é o marcador de conta encerrada.
            // Segundo fator: material de autenticação, sem finalidade nenhuma
            // depois do encerramento. Sai pelo mesmo motivo da senha aleatória
            // logo acima — se o soft delete for revertido por engano no banco, a
            // conta não pode voltar com o autenticador antigo ainda casando, e
            // os recovery codes seriam 8 bypasses prontos numa linha que
            // ninguém mais vigia.
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_ts' => null,
            // "Última atividade" (Sprint 10): o carimbo do último request da
            // performer. Sem lastro fiscal nem legal, e é exatamente o tipo de
            // rastro temporal que o encerramento apaga — some junto. A faixa
            // pública derivada dele passa a ser null (nada a exibir).
            'last_active_at' => null,
            // Watermark de "corações vistos" (feat/activity-badges): rastro
            // temporal do próprio titular, sem lastro fiscal/legal — some junto,
            // como o last_active_at. Os corações RECEBIDOS já saem em
            // purgePerformerHearts; este é só a marca d'água.
            'hearts_seen_at' => null,
            'deletion_token_hash' => null,
            'deletion_token_expires_at' => null,
        ])->save();

        $user->delete();
    }

    private function executedLogFor(User $user): ?DeletionLog
    {
        return DeletionLog::where('user_id', $user->id)
            ->whereNotNull('executed_at')
            ->latest('id')
            ->first();
    }

    /** Vencidos e ainda não encerrados — a fila do job diário. */
    public function dueForDeletion(?Carbon $now = null): Collection
    {
        return User::query()
            ->whereNotNull('deletion_scheduled_at')
            ->where('deletion_scheduled_at', '<=', $now ?? now())
            ->whereNull('deleted_at')
            ->get();
    }
}
