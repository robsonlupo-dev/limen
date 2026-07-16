<?php

namespace Database\Factories;

use App\Models\PerformerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PerformerProfile>
 */
class PerformerProfileFactory extends Factory
{
    /** Nível → split do performer (%) — espelho da regra de negócio. */
    public const LEVEL_SPLITS = [
        'iniciante' => 65,
        'estrela' => 70,
        'premium' => 75,
        'vip' => 80,
    ];

    public const WORLDS = PerformerProfile::WORLDS;

    public function definition(): array
    {
        $level = fake()->randomElement(array_keys(self::LEVEL_SPLITS));
        $stageName = ucfirst(fake()->unique()->userName());

        return [
            'stage_name' => $stageName,
            'slug' => PerformerProfile::generateSlug($stageName),
            'bio' => fake()->sentence(12),
            'category' => fake()->randomElement(self::WORLDS),
            'work_modes' => fake()->randomElements(['chat', 'private', 'camera'], fake()->numberBetween(1, 3)),
            'level' => $level,
            'split_pct' => self::LEVEL_SPLITS[$level],
            'rate_public' => fake()->randomElement([40, 60, 80]),
            'rate_private' => fake()->randomElement([100, 120, 180, 240]),
            'rate_camera' => fake()->randomElement([15, 20, 30]),
            'is_live' => fake()->boolean(20),
            'is_verified' => true,
            'rating_avg' => fake()->randomFloat(2, 3.5, 5.0),
            'rating_count' => fake()->numberBetween(3, 240),
        ];
    }

    public function world(string $world): static
    {
        return $this->state(fn () => ['category' => $world]);
    }

    public function level(string $level): static
    {
        return $this->state(fn () => [
            'level' => $level,
            'split_pct' => self::LEVEL_SPLITS[$level],
        ]);
    }
}
