<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Webhook;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WebhookFactory extends Factory
{
    protected $model = Webhook::class;

    public function definition(): array
    {
        return [
            'user_id'           => User::factory(),
            'name'              => $this->faker->words(2, true),
            'url'               => $this->faker->url(),
            'secret'            => bin2hex(random_bytes(32)),
            'events'            => ['account.created', 'account.updated'],
            'is_active'         => true,
            'last_triggered_at' => null,
        ];
    }
}
