<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ConsentLog>
 */
class ConsentLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'domain_id' => \App\Models\Domain::factory(),
            'consent_uid' => fake()->uuid(),
            'ip_hash' => fake()->md5(),
            'user_agent' => fake()->userAgent(),
            'consent_type' => 'explicit',
            'cookie_version' => 1,
            'is_latest' => true,
            'consents_granted' => ['essential', 'analytical'],
            'services_granted' => ['google-analytics'],
        ];
    }
}
