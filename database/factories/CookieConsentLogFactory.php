<?php

namespace Database\Factories;

use App\Models\CookieConsentLog;
use App\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;

class CookieConsentLogFactory extends Factory
{
    protected $model = CookieConsentLog::class;

    public function definition(): array
    {
        return [
            'domain_id' => Domain::factory(),
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'region' => $this->faker->countryCode(),
            'consent_data' => ['type' => $this->faker->randomElement(['all', 'essential', 'custom'])],
        ];
    }
}
