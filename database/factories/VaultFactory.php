<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Vault;
use Illuminate\Database\Eloquent\Factories\Factory;

class VaultFactory extends Factory
{
    protected $model = Vault::class;

    public function definition(): array
    {
        return [
            'user_id'       => User::factory(),
            'name'          => $this->faker->words(2, true),
            'is_default'    => false,
            'is_locked'     => false,
            'last_opened_at' => null,
        ];
    }
}
