<?php

namespace Database\Seeders;

use App\Models\IdentityVerification;
use App\Models\PerformerProfile;
use App\Models\TokenWallet;
use App\Models\User;
use App\Services\TokenService;
use App\Support\AvatarPlaceholder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Massa de STAGING: 50 performers + 100 membros com dados realistas PT-BR.
 *
 * SENHA PADRÃO DE TODAS AS CONTAS: Limen2026!
 * E-mails no domínio reservado @teste.limen.local (não entregável).
 *
 * Regras:
 *  - Saldos SEMPRE via TokenService (ledger append-only) — nunca UPDATE direto.
 *  - Sem FakeAsaas: crédito direto tipo `bonus` (sem histórico de pagamento).
 *  - Avatar placeholder do pravatar baixado para o disco privado (a rota
 *    performer.media serve de Storage local); fallback SVG se a rede falhar.
 *  - CPF fictício com dígito verificador válido; nunca CPF real.
 *  - Não toca nas contas base (admin/performer/consumer @limen.test).
 *  - Idempotente: re-rodar não duplica contas nem saldos.
 *  - Proibido em produção.
 */
class LimenStagingSeeder extends Seeder
{
    public const PASSWORD = 'Limen2026!';

    /** Distribuição exata dos 50 performers por mundo. */
    private const WORLD_DISTRIBUTION = [
        'mulheres' => 15,
        'homens' => 10,
        'casais' => 8,
        'trans' => 7,
        'gls' => 5,
        'swing' => 5,
    ];

    /** Distribuição proporcional dos 100 membros por mundo (2× a de performers). */
    private const MEMBER_WORLDS = [
        'mulheres' => 30,
        'homens' => 20,
        'casais' => 16,
        'trans' => 14,
        'gls' => 10,
        'swing' => 10,
    ];

    /** Saldo inicial de demo das performers (creditado via ledger, não direto). */
    private const PERFORMER_SEED_TOKENS = 500;

    /** Nível → [split_pct, quantidade]. Total: 50. */
    private const LEVELS = [
        'iniciante' => [65, 15],
        'estrela' => [70, 15],
        'premium' => [75, 12],
        'vip' => [80, 8],
    ];

    /** Nomes artísticos PT-BR por mundo (curados, na quantidade exata). */
    private const STAGE_NAMES = [
        'mulheres' => [
            'Luna Prado', 'Bella Marques', 'Valentina Fogo', 'Maya Diniz', 'Aurora Lyz',
            'Bianca Star', 'Larissa Mel', 'Duda Ferraz', 'Nina Castro', 'Isis Moreno',
            'Lívia Doce', 'Rafa Monteiro', 'Camila Lune', 'Yasmin Brava', 'Mel Rosado',
        ],
        'homens' => [
            'Thiago Bronze', 'Rafael Lobo', 'Diego Fera', 'Bruno Aço', 'Lucas Trovão',
            'Caio Marinho', 'Pedro Vulcão', 'Gustavo Rei', 'André Falcão', 'Matheus Ouro',
        ],
        'casais' => [
            'Ana & Léo', 'Bia & Rafa', 'Carla & Dudu', 'Mel & Théo',
            'Ju & Vini', 'Paty & Gui', 'Lia & Marcos', 'Nanda & Caio',
        ],
        'trans' => [
            'Alexia Vale', 'Bruna Divine', 'Kim Valentti', 'Paola Sintra',
            'Vitória Reale', 'Samara Luz', 'Dani Monroe',
        ],
        'gls' => [
            'Max Prisma', 'Lolo Neon', 'Teo Violeta', 'Kika Astral', 'Ravi Estelar',
        ],
        'swing' => [
            'Casal Vênus', 'Duo Eclipse', 'Par Perfeito SP', 'Casal Liberté', 'Dupla Fogo',
        ],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('LimenStagingSeeder cria contas de teste e não roda em produção.');

            return;
        }

        $levelPool = $this->buildLevelPool();
        $performerCount = $this->seedPerformers($levelPool);
        $memberCount = $this->seedMembers();

        $this->command?->info(sprintf(
            'Staging povoado: %d performers, %d membros (senha padrão: %s).',
            $performerCount,
            $memberCount,
            self::PASSWORD,
        ));
    }

    /** Pool de níveis embaralhado de forma estável (15/15/12/8). */
    private function buildLevelPool(): array
    {
        $pool = [];
        foreach (self::LEVELS as $level => [$split, $count]) {
            $pool = array_merge($pool, array_fill(0, $count, $level));
        }

        mt_srand(2026); // determinístico: re-rodar dá a mesma distribuição por mundo
        shuffle($pool);
        mt_srand();

        return $pool;
    }

    private function seedPerformers(array $levelPool): int
    {
        $tokenService = app(TokenService::class);
        $i = 0;

        foreach (self::WORLD_DISTRIBUTION as $world => $count) {
            foreach (array_slice(self::STAGE_NAMES[$world], 0, $count) as $stageName) {
                $i++;
                $email = sprintf('staging-performer%02d@teste.limen.local', $i);
                $level = $levelPool[$i - 1];

                $user = User::firstOrCreate(['email' => $email], [
                    'name' => $stageName,
                    'password' => Hash::make(self::PASSWORD),
                    'role' => 'performer',
                    'status' => 'active',
                    'email_verified_at' => now(),
                    'age_verified_at' => now(),
                    'birthdate' => fake('pt_BR')->dateTimeBetween('-45 years', '-19 years')->format('Y-m-d'),
                    'lgpd_consent_at' => now(),
                    'terms_version' => '1.0',
                ]);

                if ($user->preferred_world === null) {
                    $user->preferred_world = $world;
                    $user->save();
                }

                $profile = PerformerProfile::firstOrCreate(['user_id' => $user->id], [
                    'stage_name' => $stageName,
                    'slug' => PerformerProfile::generateSlug($stageName),
                    'bio' => $this->bioFor($stageName, $world),
                    'category' => $world,
                    'work_modes' => ['chat', 'private', 'camera'],
                    'level' => $level,
                    'split_pct' => self::LEVELS[$level][0],
                    'rate_public' => Arr::random([40, 60, 80]),
                    'rate_private' => Arr::random([100, 120, 180, 240]),
                    'rate_camera' => Arr::random([15, 20, 30]),
                    'is_live' => random_int(1, 100) <= 20,
                    'is_verified' => true,
                    'rating_avg' => random_int(350, 500) / 100,
                    'rating_count' => random_int(5, 300),
                ]);

                if ($user->wasRecentlyCreated) {
                    $this->storeAvatar($user, $profile, $email);
                    $this->approveKyc($user);
                    // Saldo inicial de demo SEMPRE via ledger (nunca UPDATE direto
                    // na wallet), igual aos membros — assim a carteira nasce com
                    // soma do ledger == balance. Versões antigas setavam balance
                    // direto e criavam resíduo (corrigido por tokens:reconcile-wallets).
                    $tokenService->credit($user, self::PERFORMER_SEED_TOKENS, 'bonus', null, null, 'staging_seed');
                }
            }
        }

        return $i;
    }

    private function seedMembers(): int
    {
        $tokenService = app(TokenService::class);
        $m = 0;

        foreach (self::MEMBER_WORLDS as $world => $count) {
            for ($n = 0; $n < $count; $n++) {
                $m++;
                $email = sprintf('staging-membro%03d@teste.limen.local', $m);

                $user = User::firstOrCreate(['email' => $email], [
                    'name' => fake('pt_BR')->name(),
                    'password' => Hash::make(self::PASSWORD),
                    'role' => 'consumer',
                    'status' => 'active',
                    'email_verified_at' => now(),
                    'age_verified_at' => now(),
                    'birthdate' => fake('pt_BR')->dateTimeBetween('-60 years', '-18 years')->format('Y-m-d'),
                    'lgpd_consent_at' => now(),
                    'terms_version' => '1.0',
                ]);

                if ($user->preferred_world === null) {
                    $user->preferred_world = $world;
                    $user->save();
                }

                // Saldo 0–5000 via ledger (nunca UPDATE direto). Só na criação,
                // para re-execuções não inflarem saldos.
                if ($user->wasRecentlyCreated) {
                    $tokens = Arr::random([0, 100, 250, 500, 1000, 1500, 2500, 3500, 5000]);
                    if ($tokens > 0) {
                        $tokenService->credit($user, $tokens, 'bonus', null, null, 'staging_seed');
                    } else {
                        TokenWallet::firstOrCreate(['user_id' => $user->id], ['balance' => 0]);
                    }
                }
            }
        }

        return $m;
    }

    private function bioFor(string $stageName, string $world): string
    {
        $templates = [
            '%s no mundo %s. Atendimento exclusivo, papo envolvente e shows sob medida.',
            'Sou %s — verificação completa, sigilo absoluto. Mundo %s, sessões todas as noites.',
            '%s: novidade premium no mundo %s. Primeira conversa é sempre especial.',
            'Aqui é %s. Do mundo %s direto pro seu privado — peça sua surpresa.',
        ];

        return sprintf(Arr::random($templates), $stageName, $world);
    }

    /**
     * Baixa o placeholder do pravatar para o disco privado (a rota
     * performer.media serve de Storage local). Fallback: SVG gerado offline.
     */
    private function storeAvatar(User $user, PerformerProfile $profile, string $email): void
    {
        $path = "performer-media/{$user->id}/avatar.jpg";

        try {
            $response = Http::timeout(5)->get("https://i.pravatar.cc/300?u={$email}");
            if ($response->successful()) {
                Storage::disk('local')->put($path, $response->body());
                $profile->update(['avatar_path' => $path]);

                return;
            }
        } catch (\Throwable) {
            // sem rede — cai no fallback SVG abaixo
        }

        $path = AvatarPlaceholder::store('local', $user->id, $profile->stage_name);
        $profile->update(['avatar_path' => $path]);
    }

    /** KYC aprovado com CPF fictício de dígito verificador válido. */
    private function approveKyc(User $user): void
    {
        IdentityVerification::firstOrCreate(['user_id' => $user->id], [
            'document_type' => 'cpf',
            'document_number' => $this->fakeCpf(),
            'full_legal_name' => $user->name,
            'date_of_birth' => $user->birthdate?->format('Y-m-d') ?? '1990-01-01',
            'provider' => 'fake',
            'provider_reference' => 'staging_seed_' . $user->id,
            'provider_status' => 'approved',
            'status' => 'approved',
            'age_confirmed' => true,
            'reviewed_at' => now(),
        ]);
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
}
