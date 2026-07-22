<?php

namespace Database\Factories;

use App\Models\Circle;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        $circle = Circle::where('slug', 'prestige')->first() ?? Circle::first();

        return [
            'user_id' => User::factory(),
            'circle_id' => $circle?->id,
            'asaas_subscription_id' => 'sub_fake_'.fake()->unique()->bothify('????####'),
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonthNoOverflow(),
            'next_due_date' => now()->addMonthNoOverflow()->toDateString(),
            'cancel_at_period_end' => false,
            'price_cents' => $circle?->price_cents ?? 38990,
            'card_last4' => '1234',
            'card_brand' => 'VISA',
        ];
    }

    public function circle(string $slug): static
    {
        return $this->state(function () use ($slug) {
            $circle = Circle::where('slug', $slug)->firstOrFail();

            return ['circle_id' => $circle->id, 'price_cents' => $circle->price_cents];
        });
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'current_period_end' => now()->subDay(),
        ]);
    }
}
