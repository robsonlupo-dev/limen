<?php

namespace App\Services;

use App\Mail\AccountDeletionCancelledMail;
use App\Mail\AccountDeletionRequestedMail;
use App\Models\DeletionLog;
use App\Models\Follow;
use App\Models\IdentityVerification;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
 *     tokens de sessão/API.
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
        Mail::to($email)->queue(new AccountDeletionCancelledMail());

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
            $summary['tips_scrubbed'] = $this->scrubTips($user);
            $summary['profile_visits'] = $this->purgeProfileVisits($user);
            $summary['profile_visits_received'] = $this->purgeVisitsToOwnProfile($user);
            $summary['messages_soft_deleted'] = $this->softDeleteMessages($user);
            $summary['performer_profile'] = $this->anonymizePerformerProfile($user);
            $summary['payouts_scrubbed'] = $this->scrubPayouts($user);
            $summary['payments_scrubbed'] = $this->scrubPayments($user);
            $summary['tokens_revoked'] = $user->tokens()->delete();

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

        $this->deleteFiles($filePaths);

        return $log;
    }

    // ------------------------------------------------------------------
    // Passos individuais
    // ------------------------------------------------------------------

    /**
     * Caminhos de arquivo a destruir: documentos de KYC (disco `kyc`, cifrados)
     * e mídia do perfil (disco `local`). Coletado antes do commit, apagado
     * depois — ver executeDeletion().
     *
     * @return array<int, array{disk: string, path: string}>
     */
    private function collectFilePaths(User $user): array
    {
        $paths = [];

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

        return $paths;
    }

    /** @param array<int, array{disk: string, path: string}> $paths */
    private function deleteFiles(array $paths): void
    {
        foreach ($paths as $file) {
            Storage::disk($file['disk'])->delete($file['path']);
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
            'stage_name' => '[removido] #' . $profile->id,
            // O slug é público e costuma ser o nome artístico: some junto, mas
            // continua único (a coluna tem índice único desde 15/07).
            'slug' => 'removido-' . $profile->id,
            'bio' => null,
            'avatar_path' => null,
            'cover_path' => null,
            'is_live' => false,
            'is_verified' => false,
        ])->save();

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
            'email' => 'deleted-' . hash('sha256', $user->id . '|' . config('app.key')) . '@deleted.invalid',
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
    public function dueForDeletion(?Carbon $now = null): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()
            ->whereNotNull('deletion_scheduled_at')
            ->where('deletion_scheduled_at', '<=', $now ?? now())
            ->whereNull('deleted_at')
            ->get();
    }
}
