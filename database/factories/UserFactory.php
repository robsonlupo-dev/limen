<?php

namespace Database\Factories;

use App\Models\DocumentAcceptance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => 'consumer',
            'birthdate' => fake()->dateTimeBetween('-50 years', '-18 years')->format('Y-m-d'),
            'status' => 'active',
            'lgpd_consent_at' => now(),
            'terms_version' => '1.0',
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Performer criada pela factory já nasce com os documentos aceitos — mesmo
     * espírito do `email_verified_at => now()` acima: o default é a conta em dia,
     * e o teste que quer o estado incompleto pede por ele.
     *
     * Sem isso, todo teste de área da performer teria que aceitar documentos
     * antes do arrange, e o `documents.accepted` viraria ruído em vez de guarda.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            if ($user->role !== 'performer') {
                return;
            }

            foreach (DocumentAcceptance::REQUIRED as $type) {
                $user->documentAcceptances()->create([
                    'document_type' => $type,
                    'document_version' => DocumentAcceptance::currentVersion($type),
                    'accepted_at' => now(),
                ]);
            }
        });
    }

    /** Performer que ainda não aceitou nada — estado de quem acabou de assinar. */
    public function withoutDocumentAcceptances(): static
    {
        // Roda DEPOIS do afterCreating do configure() (a fila é ordenada), então
        // apaga o que o default acabou de criar.
        return $this->afterCreating(fn (User $user) => $user->documentAcceptances()->delete());
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function performer(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'performer',
            'status' => 'pending',
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin',
            'status' => 'active',
        ]);
    }
}
