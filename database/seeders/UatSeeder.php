<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Circle;
use App\Models\IdentityVerification;
use App\Models\PerformerContent;
use App\Models\PerformerProfile;
use App\Models\Subscription;
use App\Models\TokenWallet;
use App\Models\User;
use App\Services\FollowService;
use App\Services\PerformerContentService;
use App\Services\TokenService;
use Database\Seeders\Concerns\RefusesUnsafeEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;

/**
 * Massa de UAT: contas nomeadas e previsíveis para os testes de aceitação.
 *
 * 3 performers (ana/bella/cris) + 7 membros (um por tier + free + pobre) + 1 admin
 * + 1 moderador, todos no domínio reservado @uat.limen.test. Diferente do
 * LimenTestSeeder (50+100
 * anônimos com histórico aleatório), aqui cada conta tem PAPEL fixo, para o roteiro
 * de UAT poder dizer "entre como prestige@ e desbloqueie o conteúdo Premium da Bella".
 *
 * Invariantes do projeto respeitadas:
 *  - Saldo SEMPRE via TokenService (ledger append-only) — nunca UPDATE direto (princípio nº 2).
 *  - Follows via FollowService (contador consistente).
 *  - Conteúdo via PerformerContentService (higieniza + hash + audit, caminho canônico).
 *  - Env guard fail-closed (RefusesUnsafeEnvironment): só roda em local/testing/
 *    development/staging; NUNCA em produção, pela união dos sinais de APP_ENV.
 *  - Idempotente: re-rodar não duplica contas, saldos, conteúdo nem follows.
 *  - Senha NUNCA no repo (princípio nº 5): vem de `seedPassword()` — SEED_ADMIN_
 *    PASSWORD, com fallback conhecido só em local/testing. Em staging exige a env
 *    (senão aborta): staging é alcançável (vhost thelimen.com.br), e um seeder com
 *    senha hardcoded reabriria o buraco pelo lado (o env guard libera staging).
 *    Mesma regra do LimenTestSeeder/LimenStagingSeeder — este era o único desvio.
 */
class UatSeeder extends Seeder
{
    use RefusesUnsafeEnvironment;

    private const DOMAIN = '@uat.limen.test';

    /**
     * Idade das contas de membro. O Piso de Anonimato (item 4 da tarefa) só conta
     * seguidores com 7+ dias E e-mail verificado (mitigação de sybil —
     * FollowerVisibilityService::applyFloorEligibility). Contas criadas "hoje" não
     * destravariam a lista de seguidores, então backdatamos para que os follows de
     * fato ativem o piso na tela da performer.
     */
    private const MEMBER_AGE_DAYS = 8;

    /**
     * Performers: handle => [stage_name, category, [conteúdo...], call_price|null].
     * Conteúdo: [access_level, price_tokens].
     */
    private const PERFORMERS = [
        // stage_name é único GLOBAL — o marcador "UAT" evita colidir com a massa
        // de staging (LimenStagingSeeder), que nunca o usa.
        'ana' => ['Ana UAT', 'mulheres', [], null],
        'bella' => ['Bella UAT', 'mulheres', [
            [PerformerContent::LEVEL_OPEN, 10],
            [PerformerContent::LEVEL_PREMIUM, 20],
            [PerformerContent::LEVEL_EXCLUSIVE, 50],
        ], null],
        'cris' => ['Cris UAT', 'trans', [
            [PerformerContent::LEVEL_OPEN, 10],
            [PerformerContent::LEVEL_PREMIUM, 25],
            [PerformerContent::LEVEL_EXCLUSIVE, 50],
            [PerformerContent::LEVEL_FC_ONLY, 100],
            [PerformerContent::LEVEL_OPEN, 5],
        ], 10],
    ];

    /** Membros: handle => [circle_slug|null, tokens_iniciais]. */
    private const MEMBERS = [
        'free' => [null, 5000],
        'explorador' => ['explorador', 105],
        'insider' => ['insider', 230],
        'prestige' => ['prestige', 490],
        'black' => ['black', 1000],
        'fc' => ['founders_circle', 2100],
        'pobre' => [null, 3],
    ];

    public function run(): void
    {
        // Fail-closed pela união dos sinais de APP_ENV: aborta em produção sem
        // tocar no banco. Mesma disciplina do LimenTestSeeder/LimenStagingSeeder.
        if (! $this->safeToSeed()) {
            return;
        }

        // Lança se SEED_ADMIN_PASSWORD faltar fora de local/testing (fail-closed):
        // melhor abortar do que criar contas de equipe com credencial pública num
        // ambiente alcançável. Fallback conhecido só em local/testing.
        $password = $this->seedPassword();

        $performers = $this->seedPerformers($password);
        $this->seedMembers($password);
        $this->seedAdmin($password);
        $this->seedModerator($password);
        $followedPairs = $this->seedFollows($performers);

        $this->report($performers, $followedPairs, $password);
    }

    /**
     * Cria as 3 performers (ativas, verificadas, KYC aprovado) com seu conteúdo.
     *
     * @return array<string, PerformerProfile>
     */
    private function seedPerformers(string $password): array
    {
        $contentService = app(PerformerContentService::class);
        $profiles = [];

        foreach (self::PERFORMERS as $handle => [$stageName, $category, $content, $callPrice]) {
            $user = User::firstOrCreate(
                ['email' => $handle.self::DOMAIN],
                [
                    'name' => $stageName,
                    'password' => Hash::make($password),
                    'role' => 'performer',
                    'status' => 'active',
                    'email_verified_at' => now(),
                    'age_verified_at' => now(),
                    'birthdate' => now()->subYears(28)->format('Y-m-d'),
                    'lgpd_consent_at' => now(),
                    'terms_version' => '1.0',
                ],
            );

            // `role`/`status` são autoridade do servidor (fora do $fillable): o
            // firstOrCreate acima os DESCARTA. Sem isto, uma conta nova sairia
            // consumer/pending — o seed viraria uma armadilha em banco novo.
            $this->forceRole($user, Role::Performer->value);

            $profile = PerformerProfile::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'stage_name' => $stageName,
                    'slug' => PerformerProfile::generateSlug($stageName),
                    'bio' => "Conta de UAT — {$stageName}. Dados sintéticos.",
                    'category' => $category,
                    'level' => 'estrela',
                    'is_verified' => true,
                ],
            );

            // call_price_per_minute está FORA do $fillable (snapshot congelado no
            // request pelo CallService) — set direto, nunca por mass assignment.
            if ($callPrice !== null && $profile->call_price_per_minute !== $callPrice) {
                $profile->call_price_per_minute = $callPrice;
                $profile->save();
            }

            if ($user->wasRecentlyCreated) {
                $this->approveKyc($user);
                // Performer nasce com saldo 0 (ganha via gorjeta/sessão). Wallet 0
                // sem lançamento é consistente (soma do ledger vazio = 0).
                TokenWallet::firstOrCreate(['user_id' => $user->id], ['balance' => 0]);
            }

            $this->seedContent($contentService, $profile, $content);

            $profiles[$handle] = $profile;
        }

        return $profiles;
    }

    /**
     * Publica as peças de conteúdo da performer, idempotente por (nível, preço).
     *
     * @param  array<int, array{0: string, 1: int}>  $pieces
     */
    private function seedContent(PerformerContentService $service, PerformerProfile $profile, array $pieces): void
    {
        foreach ($pieces as [$level, $price]) {
            $exists = PerformerContent::where('performer_profile_id', $profile->id)
                ->where('access_level', $level)
                ->where('price_tokens', $price)
                ->exists();

            if ($exists) {
                continue;
            }

            // Caminho canônico: higieniza (re-encode mata EXIF/polyglot), grava no
            // disco privado, calcula content_hash e faz audit. Preço server-validado
            // (piso 5, passo 5 — M.13.7).
            $service->publish($profile, $this->fakeImage("{$profile->stage_name} {$level}"), $level, $price);
        }
    }

    /** Cria os 7 membros com tier + saldo (via ledger), backdatados para o Piso. */
    private function seedMembers(string $password): void
    {
        $tokenService = app(TokenService::class);

        foreach (self::MEMBERS as $handle => [$circleSlug, $tokens]) {
            $user = User::firstOrCreate(
                ['email' => $handle.self::DOMAIN],
                [
                    'name' => 'UAT '.ucfirst($handle),
                    'password' => Hash::make($password),
                    'role' => 'consumer',
                    'status' => 'active',
                    'email_verified_at' => now(),
                    'age_verified_at' => now(),
                    'birthdate' => now()->subYears(30)->format('Y-m-d'),
                    'lgpd_consent_at' => now(),
                    'terms_version' => '1.0',
                ],
            );

            // Mesmo motivo do performer: role/status não são fillable e o
            // firstOrCreate os descarta. Força consumer/active (idempotente).
            $this->forceRole($user, Role::Consumer->value);

            if ($user->wasRecentlyCreated) {
                // Backdate para o membro contar no Piso de Anonimato (7+ dias).
                // save() na atualização só mexe em updated_at; created_at persiste.
                $user->created_at = now()->subDays(self::MEMBER_AGE_DAYS);
                $user->save();

                // Saldo via ledger append-only (nunca UPDATE direto — princípio nº 2).
                // Só na criação, para re-execuções não inflarem o saldo.
                if ($tokens > 0) {
                    $tokenService->credit($user, $tokens, 'purchase', null, null, 'uat_seed');
                }
            }

            if ($circleSlug !== null) {
                $this->subscribe($user, $circleSlug);
            }
        }
    }

    /** Assinatura ativa idempotente por (usuário, círculo), período de 30 dias. */
    private function subscribe(User $user, string $circleSlug): void
    {
        $circle = Circle::where('slug', $circleSlug)->first();

        if ($circle === null) {
            $this->command?->warn("Círculo '{$circleSlug}' inexistente — assinatura de {$user->email} pulada.");

            return;
        }

        Subscription::updateOrCreate(
            ['user_id' => $user->id, 'circle_id' => $circle->id],
            [
                'status' => 'active',
                'current_period_start' => now(),
                'current_period_end' => now()->addDays(30),
                'next_due_date' => now()->addDays(30),
                'price_cents' => $circle->price_cents,
            ],
        );
    }

    /** Admin: controle total (dinheiro, tier, KYC, ban, config + moderação). */
    private function seedAdmin(string $password): void
    {
        $this->seedStaff('admin', Role::Admin->value, 'UAT Admin', $password);
    }

    /**
     * Moderador (Trust & Safety): revisa a fila /moderacao/* SEM poderes de admin
     * — não vê dinheiro, tier nem KYC. Faltava no seed: a conta de UAT existia
     * criada à mão como admin/pending, e era a raiz do 403 relatado. Agora nasce
     * moderator/active pelo caminho certo, e o seed conserta a linha antiga.
     */
    private function seedModerator(string $password): void
    {
        $this->seedStaff('moderador', Role::Moderator->value, 'UAT Moderador', $password);
    }

    /**
     * Conta de equipe (admin/moderador). `role`/`status` são autoridade do
     * servidor (fora do $fillable), então o firstOrCreate os DESCARTA — uma conta
     * nova sairia consumer/pending. Aqui os campos privilegiados vão por forceFill,
     * idempotente e AUTO-CORRETIVO: uma linha já existente com papel/status errado
     * (o caso do moderador@uat criado à mão como admin/pending) é consertada na
     * próxima execução. A senha é reforçada para a de UAT (`seedPassword`),
     * garantindo que o testador loga mesmo numa conta pré-existente de senha
     * desconhecida — nunca uma credencial hardcoded (ver docblock da classe).
     */
    private function seedStaff(string $handle, string $role, string $name, string $password): void
    {
        $user = User::firstOrCreate(
            ['email' => $handle.self::DOMAIN],
            [
                'name' => $name,
                // Senha no INSERT (coluna NOT NULL, sem default); o forceFill
                // abaixo a reforça para o valor padrão numa conta pré-existente.
                'password' => Hash::make($password),
                'lgpd_consent_at' => now(),
                'terms_version' => '1.0',
            ],
        );

        // O cast `hashed` cifra a senha ao setar, mesmo via forceFill (mesmo
        // caminho do comando limen:create-moderator). Reforçar a senha garante
        // que o testador loga mesmo numa conta pré-existente de senha desconhecida.
        $user->forceFill([
            'role' => $role,
            'status' => 'active',
            'password' => $password,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();
    }

    /**
     * Grava `role`/`status` de uma conta de membro/performer — os dois ficam fora
     * do $fillable (autoridade do servidor), então o firstOrCreate os descarta.
     * Idempotente e auto-corretivo; escreve só quando diverge, para não gerar
     * UPDATE inútil em re-execução.
     */
    private function forceRole(User $user, string $role): void
    {
        if ($user->role === $role && $user->status === 'active') {
            return;
        }

        $user->forceFill(['role' => $role, 'status' => 'active'])->save();
    }

    /**
     * Todos os membros seguem todas as performers (item 4 — ativa o Piso). Via
     * FollowService (contador consistente + firstOrCreate idempotente).
     *
     * @param  array<string, PerformerProfile>  $performers
     * @return int  pares (membro, performer) processados
     */
    private function seedFollows(array $performers): int
    {
        $followService = app(FollowService::class);
        $members = User::where('email', 'like', '%'.self::DOMAIN)
            ->where('role', 'consumer')
            ->get();

        $pairs = 0;
        foreach ($members as $member) {
            foreach ($performers as $profile) {
                $followService->follow($member, $profile);
                $pairs++;
            }
        }

        return $pairs;
    }

    /** KYC aprovado com CPF fictício de dígito verificador válido (nunca CPF real). */
    private function approveKyc(User $user): void
    {
        IdentityVerification::firstOrCreate(
            ['user_id' => $user->id],
            [
                'document_type' => 'cpf',
                'document_number' => $this->fakeCpf(),
                'full_legal_name' => $user->name,
                'date_of_birth' => $user->birthdate?->format('Y-m-d') ?? '1990-01-01',
                'provider' => 'fake',
                'provider_reference' => 'uat_seed_'.$user->id,
                'provider_status' => 'approved',
                'status' => 'approved',
                'age_confirmed' => true,
                'reviewed_at' => now(),
            ],
        );
    }

    /**
     * JPEG sintético (GD) como UploadedFile de teste, para o
     * PerformerContentService higienizar e persistir. Sem rede, sem pessoa real.
     */
    private function fakeImage(string $label): UploadedFile
    {
        $img = imagecreatetruecolor(800, 600);
        imagefilledrectangle($img, 0, 0, 800, 600, imagecolorallocate($img, 40, 20, 60));
        imagestring($img, 5, 20, 20, $label, imagecolorallocate($img, 230, 210, 120));

        $path = tempnam(sys_get_temp_dir(), 'uat_img_');
        imagejpeg($img, $path, 85);
        imagedestroy($img);

        // $test = true: não exige que tenha vindo de um upload HTTP real.
        return new UploadedFile($path, 'uat.jpg', 'image/jpeg', null, true);
    }

    /** CPF fictício com dígitos verificadores válidos (algoritmo oficial). */
    private function fakeCpf(): string
    {
        $digits = [];
        for ($i = 0; $i < 9; $i++) {
            $digits[] = random_int(0, 9);
        }

        for ($j = 0; $j < 2; $j++) {
            $sum = 0;
            $len = count($digits);
            foreach ($digits as $pos => $digit) {
                $sum += $digit * (($len + 1) - $pos);
            }
            $digits[] = ($sum * 10) % 11 % 10;
        }

        return implode('', $digits);
    }

    /** @param  array<string, PerformerProfile>  $performers */
    private function report(array $performers, int $followedPairs, string $password): void
    {
        $uat = User::where('email', 'like', '%'.self::DOMAIN);

        $this->command?->info(sprintf(
            'UAT pronto: %d contas (@uat.limen.test) — %d performers, %d membros, 1 admin, 1 moderador. '
            .'%d peças de conteúdo, %d pares de follow. Senha: %s',
            (clone $uat)->count(),
            count($performers),
            count(self::MEMBERS),
            PerformerContent::whereIn('performer_profile_id', array_map(fn ($p) => $p->id, $performers))->count(),
            $followedPairs,
            $password,
        ));
    }
}
