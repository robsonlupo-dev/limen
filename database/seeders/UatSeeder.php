<?php

namespace Database\Seeders;

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
 * 3 performers (ana/bella/cris) + 7 membros (um por tier + free + pobre) + 1 admin,
 * todos no domínio reservado @uat.limen.test. Diferente do LimenTestSeeder (50+100
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
 *
 * DESVIO consciente da convenção "senha nunca no repo": UAT exige credencial
 * CONHECIDA para o testador logar. As contas são descartáveis (@uat.limen.test,
 * não entregável) e o env guard já barra produção. A senha vale só para esta massa.
 */
class UatSeeder extends Seeder
{
    use RefusesUnsafeEnvironment;

    /** Senha comum das contas de UAT (ver o desvio no docblock da classe). */
    private const PASSWORD = 'UatLimen2026!';

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

        $performers = $this->seedPerformers();
        $this->seedMembers();
        $this->seedAdmin();
        $followedPairs = $this->seedFollows($performers);

        $this->report($performers, $followedPairs);
    }

    /**
     * Cria as 3 performers (ativas, verificadas, KYC aprovado) com seu conteúdo.
     *
     * @return array<string, PerformerProfile>
     */
    private function seedPerformers(): array
    {
        $contentService = app(PerformerContentService::class);
        $profiles = [];

        foreach (self::PERFORMERS as $handle => [$stageName, $category, $content, $callPrice]) {
            $user = User::firstOrCreate(
                ['email' => $handle.self::DOMAIN],
                [
                    'name' => $stageName,
                    'password' => Hash::make(self::PASSWORD),
                    'role' => 'performer',
                    'status' => 'active',
                    'email_verified_at' => now(),
                    'age_verified_at' => now(),
                    'birthdate' => now()->subYears(28)->format('Y-m-d'),
                    'lgpd_consent_at' => now(),
                    'terms_version' => '1.0',
                ],
            );

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
    private function seedMembers(): void
    {
        $tokenService = app(TokenService::class);

        foreach (self::MEMBERS as $handle => [$circleSlug, $tokens]) {
            $user = User::firstOrCreate(
                ['email' => $handle.self::DOMAIN],
                [
                    'name' => 'UAT '.ucfirst($handle),
                    'password' => Hash::make(self::PASSWORD),
                    'role' => 'consumer',
                    'status' => 'active',
                    'email_verified_at' => now(),
                    'age_verified_at' => now(),
                    'birthdate' => now()->subYears(30)->format('Y-m-d'),
                    'lgpd_consent_at' => now(),
                    'terms_version' => '1.0',
                ],
            );

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

    private function seedAdmin(): void
    {
        User::firstOrCreate(
            ['email' => 'admin'.self::DOMAIN],
            [
                'name' => 'UAT Admin',
                'password' => Hash::make(self::PASSWORD),
                'role' => 'admin',
                'status' => 'active',
                'email_verified_at' => now(),
                'lgpd_consent_at' => now(),
                'terms_version' => '1.0',
            ],
        );
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
    private function report(array $performers, int $followedPairs): void
    {
        $uat = User::where('email', 'like', '%'.self::DOMAIN);

        $this->command?->info(sprintf(
            'UAT pronto: %d contas (@uat.limen.test) — %d performers, %d membros, 1 admin. '
            .'%d peças de conteúdo, %d pares de follow. Senha: %s',
            (clone $uat)->count(),
            count($performers),
            count(self::MEMBERS),
            PerformerContent::whereIn('performer_profile_id', array_map(fn ($p) => $p->id, $performers))->count(),
            $followedPairs,
            self::PASSWORD,
        ));
    }
}
