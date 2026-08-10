<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEndpoint>
 */
class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'name' => fake()->words(2, true),
            'url' => 'https://example.test/hooks/'.Str::random(8),
            'secret' => Str::random(32),
            'events' => [WebhookEndpoint::EVENT_SCAN_COMPLETED],
            'is_active' => true,
        ];
    }
}
