<?php

namespace Database\Seeders;

use App\Models\PerformerProfile;
use App\Models\TokenPackage;
use App\Models\User;
use Database\Seeders\Concerns\RefusesUnsafeEnvironment;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use RefusesUnsafeEnvironment;
    use WithoutModelEvents;

    public function run(): void
    {
        // Contas de teste com senha conhecida jamais podem nascer em produção.
        if (! $this->safeToSeed()) {
            return;
        }

        $this->seedTokenPackages();
        $this->seedUsers();
    }

    private function seedTokenPackages(): void
    {
        $packages = [
            ['slug' => 'bronze',   'name' => 'Bronze',   'tokens' => 100,  'bonus' => 0,    'price_cents' => 990,   'sort_order' => 1],
            ['slug' => 'prata',    'name' => 'Prata',    'tokens' => 250,  'bonus' => 25,   'price_cents' => 2490,  'sort_order' => 2],
            ['slug' => 'ouro',     'name' => 'Ouro',     'tokens' => 500,  'bonus' => 75,   'price_cents' => 4990,  'sort_order' => 3],
            ['slug' => 'platina',  'name' => 'Platina',  'tokens' => 1000, 'bonus' => 200,  'price_cents' => 9990,  'sort_order' => 4],
            ['slug' => 'diamante', 'name' => 'Diamante', 'tokens' => 2500, 'bonus' => 600,  'price_cents' => 24990, 'sort_order' => 5],
            ['slug' => 'black',    'name' => 'Black',    'tokens' => 5000, 'bonus' => 1500, 'price_cents' => 49990, 'sort_order' => 6],
        ];

        foreach ($packages as $pkg) {
            TokenPackage::updateOrCreate(['slug' => $pkg['slug']], $pkg);
        }
    }

    /**
     * Senha das contas base (admin/performer/consumer @limen.test). O fallback
     * conhecido (`Password1`) só é aceitável em ambientes descartáveis
     * (local/testing, exigidos pela UNIÃO de sinais — ver isEnvironment). Em
     * qualquer outro ambiente da allowlist — staging, development — exige
     * SEED_ADMIN_PASSWORD explícita, senão aborta: nunca criar contas reais com
     * credencial pública num ambiente alcançável (staging é exposto via túnel).
     */
    private function seedPassword(): string
    {
        // Leitura bruta (imune a config:cache) com fallback para env().
        $password = $this->rawEnv('SEED_ADMIN_PASSWORD') ?? env('SEED_ADMIN_PASSWORD');
        if (is_string($password) && $password !== '') {
            return $password;
        }

        if ($this->isEnvironment(['local', 'testing'])) {
            return 'Password1';
        }

        throw new \RuntimeException(
            'SEED_ADMIN_PASSWORD é obrigatória fora de local/testing: recuse-se a '
            . 'criar contas base (admin/performer/consumer) com senha default.',
        );
    }

    private function seedUsers(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@limen.test'],
            [
                'name' => 'Admin Limen',
                'password' => $this->seedPassword(),
                'role' => 'admin',
                'status' => 'active',
                'birthdate' => '1990-01-15',
                'lgpd_consent_at' => now(),
                'terms_version' => '1.0',
            ],
        );

        $performer = User::firstOrCreate(
            ['email' => 'performer@limen.test'],
            [
                'name' => 'Performer Teste',
                'password' => $this->seedPassword(),
                'role' => 'performer',
                'status' => 'pending',
                'birthdate' => '1995-06-20',
                'lgpd_consent_at' => now(),
                'terms_version' => '1.0',
            ],
        );

        $profile = PerformerProfile::firstOrCreate(
            ['user_id' => $performer->id],
            [
                'stage_name' => 'StarTest',
                'slug' => PerformerProfile::generateSlug('StarTest'),
                'bio' => 'Perfil de teste para desenvolvimento.',
                'category' => 'mulheres',
                'work_modes' => ['chat', 'private', 'camera'],
                'level' => 'iniciante',
            ],
        );

        // Backfill idempotente para bancos onde o perfil já existia sem slug
        // ou com work_modes fora do vocabulário real (chat/private/camera).
        if (! $profile->slug) {
            $profile->slug = PerformerProfile::generateSlug($profile->stage_name);
        }
        if (array_diff($profile->work_modes ?? [], ['chat', 'private', 'camera'])) {
            $profile->work_modes = ['chat', 'private', 'camera'];
        }
        if ($profile->isDirty()) {
            $profile->save();
        }

        $consumer = User::firstOrCreate(
            ['email' => 'consumer@limen.test'],
            [
                'name' => 'Consumer Teste',
                'password' => $this->seedPassword(),
                'role' => 'consumer',
                'status' => 'active',
                'birthdate' => '1998-03-10',
                'lgpd_consent_at' => now(),
                'terms_version' => '1.0',
            ],
        );

        // preferred_world fica fora do mass-assignment; backfill explícito
        // alinhado à categoria do performer de teste.
        if ($consumer->preferred_world === null) {
            $consumer->preferred_world = 'mulheres';
            $consumer->save();
        }
    }
}
