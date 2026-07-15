<?php

namespace Database\Seeders;

use App\Models\IdentityVerification;
use App\Models\PerformerProfile;
use App\Models\TokenLedger;
use App\Models\TokenPackage;
use App\Models\TokenWallet;
use App\Models\User;
use App\Services\Asaas\AsaasClientInterface;
use App\Services\Asaas\FakeAsaasClient;
use App\Services\FollowService;
use App\Services\PaymentService;
use App\Services\TipService;
use App\Services\TokenService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Massa de QA: 50 performers + 100 membros com histórico realista.
 *
 * Regras invioláveis (LIMEN-QA-OPERATION.md):
 *  - Saldo SEMPRE via TokenService/ledger — nunca UPDATE direto de balance.
 *  - Compras via PaymentService + FakeAsaasClient (webhook idempotente real).
 *  - Gorjetas via TipService (split real por nível).
 *  - Follows via FollowService (contadores consistentes).
 *  - Só placeholders de imagem (SVG gerado localmente); CPF fake com DV válido;
 *    e-mails @teste.limen.local; senha padrão documentada em docs/qa/TEST_ACCOUNTS.md.
 *  - Idempotente: re-rodar não duplica contas nem históricos.
 */
class LimenTestSeeder extends Seeder
{
    public const PASSWORD = 'Limen@2026';

    /** Distribuição dos 50 performers pelos 6 mundos. */
    private const WORLD_DISTRIBUTION = [
        'mulheres' => 20,
        'homens' => 8,
        'casais' => 8,
        'trans' => 6,
        'gls' => 5,
        'swing' => 3,
    ];

    private const WORLD_COLORS = [
        'mulheres' => ['#7c2d5e', '#c9a227'],
        'homens' => ['#1e3a5f', '#c9a227'],
        'casais' => ['#5b2d7c', '#c9a227'],
        'trans' => ['#2d7c6b', '#c9a227'],
        'gls' => ['#7c5e2d', '#c9a227'],
        'swing' => ['#7c2d2d', '#c9a227'],
    ];

    private const STAGE_SUFFIXES = ['Luz', 'Bella', 'Rex', 'Nyx', 'Vip', 'Fox', 'Star', 'Lua', 'Real', 'Mel', 'Fire', 'Doce'];

    private const BIO_TEMPLATES = [
        'Seu momento de fuga favorito. Atendo com carinho e exclusividade no mundo %s.',
        'Experiência premium, papo envolvente e shows sob medida. Vem me conhecer.',
        'Aqui o clima é quente e o sigilo é absoluto. Gorjetas fazem meu show brilhar.',
        'Verificada e pronta pra te receber. Sessões privadas todos os dias à noite.',
        'Do catálogo %s direto pro seu coração. Peça sua música na sessão privada.',
        'Novidade na Limen — carinhosa, criativa e sem pressa. Primeira conversa é especial.',
        'Top do mundo %s. Shows ao vivo, atendimento VIP e surpresas pra seguidores fiéis.',
        'Discrição total, entrega total. Meu privado é o seu lugar seguro.',
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('LimenTestSeeder é proibido em produção.');

            return;
        }

        // Em `local` o container liga o AsaasHttpClient real; a massa de QA
        // exige o fake. Força o binding só para este processo de seed.
        app()->singleton(AsaasClientInterface::class, fn () => new FakeAsaasClient());

        // Pacotes de tokens + contas base (idempotente).
        $this->call(DatabaseSeeder::class);

        $performers = $this->seedPerformers();
        $members = $this->seedMembers();
        $this->seedHistory($members, $performers);

        $this->command?->info(sprintf(
            'Massa pronta: %d performers, %d membros, %d follows, %d tips, %d payments.',
            $performers->count(),
            $members->count(),
            \App\Models\Follow::count(),
            \App\Models\Tip::count(),
            \App\Models\Payment::count(),
        ));
    }

    /** @return \Illuminate\Support\Collection<int, PerformerProfile> */
    private function seedPerformers()
    {
        $levels = array_keys(\Database\Factories\PerformerProfileFactory::LEVEL_SPLITS);
        $profiles = collect();
        $i = 0;

        foreach (self::WORLD_DISTRIBUTION as $world => $count) {
            for ($n = 0; $n < $count; $n++) {
                $i++;
                $email = sprintf('performer%02d@teste.limen.local', $i);

                $user = User::firstOrCreate(['email' => $email], [
                    'name' => fake('pt_BR')->name(),
                    'password' => Hash::make(self::PASSWORD),
                    'role' => 'performer',
                    'status' => 'active',
                    'email_verified_at' => now(),
                    'age_verified_at' => now(),
                    'birthdate' => fake()->dateTimeBetween('-45 years', '-19 years')->format('Y-m-d'),
                    'lgpd_consent_at' => now(),
                    'terms_version' => '1.0',
                ]);

                $stageName = fake()->unique()->firstName() . ' ' . Arr::random(self::STAGE_SUFFIXES);
                $level = $levels[$i % count($levels)];

                $profile = PerformerProfile::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'stage_name' => $stageName,
                        'slug' => PerformerProfile::generateSlug($stageName),
                        'bio' => sprintf(Arr::random(self::BIO_TEMPLATES), $world),
                    ] + PerformerProfile::factory()->world($world)->level($level)->raw(),
                );

                if ($user->wasRecentlyCreated) {
                    $this->writePlaceholderMedia($user, $profile, $world, $stageName);
                    $this->approveKyc($user);
                }

                TokenWallet::firstOrCreate(['user_id' => $user->id], ['balance' => 0]);
                $profiles->push($profile);
            }
        }

        return $profiles;
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function seedMembers()
    {
        $tokenService = app(TokenService::class);
        $worlds = array_keys(self::WORLD_DISTRIBUTION);
        $members = collect();

        for ($m = 1; $m <= 100; $m++) {
            $email = sprintf('membro%03d@teste.limen.local', $m);

            $user = User::firstOrCreate(['email' => $email], [
                'name' => fake('pt_BR')->name(),
                'password' => Hash::make(self::PASSWORD),
                'role' => 'consumer',
                'status' => 'active',
                'email_verified_at' => now(),
                'age_verified_at' => now(),
                'birthdate' => fake()->dateTimeBetween('-60 years', '-18 years')->format('Y-m-d'),
                'lgpd_consent_at' => now(),
                'terms_version' => '1.0',
            ]);

            // preferred_world está (corretamente) fora do mass-assignment.
            if ($user->preferred_world === null) {
                $user->preferred_world = Arr::random($worlds);
                $user->save();
            }

            if ($user->wasRecentlyCreated) {
                $initial = Arr::random([0, 200, 500, 1200, 2000, 6000]);
                if ($initial > 0) {
                    $tokenService->credit($user, $initial, 'bonus', null, null, 'seed_initial');
                }
            }

            $members->push($user);
        }

        return $members;
    }

    private function seedHistory($members, $performers): void
    {
        $paymentService = app(PaymentService::class);
        $asaas = app(AsaasClientInterface::class);
        $tipService = app(TipService::class);
        $followService = app(FollowService::class);
        $tokenService = app(TokenService::class);
        $packages = TokenPackage::where('active', true)->get();

        foreach ($members as $idx => $member) {
            // Idempotência do histórico: só gera para membros sem rastro anterior.
            $hasHistory = \App\Models\Payment::where('user_id', $member->id)->exists()
                || \App\Models\Follow::where('user_id', $member->id)->exists();
            if ($hasHistory) {
                continue;
            }

            // 0–5 compras confirmadas via webhook idempotente (FakeAsaas).
            $purchaseCount = random_int(0, 5);
            for ($p = 0; $p < $purchaseCount; $p++) {
                if ($packages->isEmpty()) {
                    break;
                }
                $payment = $paymentService->createPayment($member, $packages->random(), $this->fakeCpf());
                $asaas->simulatePaymentReceived($payment->provider_charge_id);
                $paymentService->handleWebhook([
                    'id' => 'evt_seed_' . uniqid('', true),
                    'event' => 'PAYMENT_RECEIVED',
                    'payment' => ['id' => $payment->provider_charge_id],
                ]);
            }

            // 0–15 follows.
            foreach ($performers->random(min(random_int(0, 15), $performers->count())) as $profile) {
                $followService->follow($member, $profile);
            }

            // 0–10 gorjetas (para o quem o membro segue, quando possível).
            $tipCount = random_int(0, 10);
            for ($t = 0; $t < $tipCount; $t++) {
                $amount = Arr::random([5, 10, 25, 50, 100]);
                if ($tokenService->balance($member) < $amount) {
                    break;
                }
                $tipService->send(
                    $member,
                    $performers->random(),
                    $amount,
                    sprintf('seed_tip_%d_%d_%s', $member->id, $t, uniqid()),
                    Arr::random(['Você merece!', 'Show incrível 🔥', 'Até a próxima!', null]),
                );
            }
        }
    }

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
                'provider_reference' => 'fake_seed_' . $user->id,
                'provider_status' => 'approved',
                'status' => 'approved',
                'age_confirmed' => true,
                'reviewed_at' => now(),
            ],
        );
    }

    /** CPF fictício com dígitos verificadores válidos (nunca CPF real). */
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

    /** SVG placeholder local (sem rede, sem pessoa real) no disco privado. */
    private function writePlaceholderMedia(User $user, PerformerProfile $profile, string $world, string $stageName): void
    {
        [$bg, $accent] = self::WORLD_COLORS[$world];
        $initials = mb_strtoupper(mb_substr($stageName, 0, 1) . mb_substr(strrchr($stageName, ' ') ?: ' ?', 1, 1));

        $avatar = <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" width="300" height="300" viewBox="0 0 300 300">
          <defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="{$bg}"/><stop offset="1" stop-color="#111"/>
          </linearGradient></defs>
          <rect width="300" height="300" fill="url(#g)"/>
          <circle cx="150" cy="150" r="120" fill="none" stroke="{$accent}" stroke-width="3"/>
          <text x="150" y="172" font-family="Georgia, serif" font-size="96" fill="{$accent}" text-anchor="middle">{$initials}</text>
        </svg>
        SVG;

        $cover = <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" width="1200" height="400" viewBox="0 0 1200 400">
          <defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stop-color="#111"/><stop offset="0.5" stop-color="{$bg}"/><stop offset="1" stop-color="#111"/>
          </linearGradient></defs>
          <rect width="1200" height="400" fill="url(#g)"/>
          <text x="600" y="215" font-family="Georgia, serif" font-size="64" fill="{$accent}" text-anchor="middle">{$stageName}</text>
        </svg>
        SVG;

        Storage::disk('local')->put("performer-media/{$user->id}/avatar.svg", $avatar);
        Storage::disk('local')->put("performer-media/{$user->id}/cover.svg", $cover);

        $profile->update([
            'avatar_path' => "performer-media/{$user->id}/avatar.svg",
            'cover_path' => "performer-media/{$user->id}/cover.svg",
        ]);
    }
}
