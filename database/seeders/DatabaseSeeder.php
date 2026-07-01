<?php

namespace Database\Seeders;

use App\Models\PerformerProfile;
use App\Models\TokenPackage;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
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

    private function seedUsers(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@limen.test'],
            [
                'name' => 'Admin Limen',
                'password' => 'Password1',
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
                'password' => 'Password1',
                'role' => 'performer',
                'status' => 'pending',
                'birthdate' => '1995-06-20',
                'lgpd_consent_at' => now(),
                'terms_version' => '1.0',
            ],
        );

        PerformerProfile::firstOrCreate(
            ['user_id' => $performer->id],
            [
                'stage_name' => 'StarTest',
                'bio' => 'Perfil de teste para desenvolvimento.',
                'category' => 'mulheres',
                'work_modes' => ['streaming', 'videos'],
                'level' => 'iniciante',
            ],
        );

        User::firstOrCreate(
            ['email' => 'consumer@limen.test'],
            [
                'name' => 'Consumer Teste',
                'password' => 'Password1',
                'role' => 'consumer',
                'status' => 'active',
                'birthdate' => '1998-03-10',
                'lgpd_consent_at' => now(),
                'terms_version' => '1.0',
            ],
        );
    }
}
