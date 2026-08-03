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
        // Pacotes achatados da emenda M.13.2 (Sprint 14). `tokens` já inclui o
        // valor cheio do pacote; sem `bonus` no modelo novo (a âncora é
        // R$1,00/token no Starter). Preço em centavos.
        $packages = [
            ['slug' => 'starter', 'name' => 'Starter', 'tokens' => 50,  'bonus' => 0, 'price_cents' => 4990,  'sort_order' => 1],
            ['slug' => 'popular', 'name' => 'Popular', 'tokens' => 105, 'bonus' => 0, 'price_cents' => 9990,  'sort_order' => 2],
            ['slug' => 'premium', 'name' => 'Premium', 'tokens' => 220, 'bonus' => 0, 'price_cents' => 19990, 'sort_order' => 3],
            ['slug' => 'vip',     'name' => 'VIP',     'tokens' => 580, 'bonus' => 0, 'price_cents' => 49990, 'sort_order' => 4],
        ];

        foreach ($packages as $pkg) {
            TokenPackage::updateOrCreate(['slug' => $pkg['slug']], $pkg + ['active' => true]);
        }

        // Desativa (NUNCA apaga — a FK de `payments` guarda o histórico) qualquer
        // pacote pré-M.13 que exista neste ambiente, para o catálogo de compra só
        // oferecer os pacotes M.13.2.
        TokenPackage::whereNotIn('slug', array_column($packages, 'slug'))
            ->update(['active' => false]);
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
