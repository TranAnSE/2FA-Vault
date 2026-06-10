<?php

namespace Database\Factories;

use App\Models\EmergencyContact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmergencyContactFactory extends Factory
{
    protected $model = EmergencyContact::class;

    public function definition(): array
    {
        return [
            'owner_id'        => User::factory(),
            'trusted_user_id' => User::factory(),
            'email'           => $this->faker->unique()->safeEmail(),
            'status'          => 'confirmed',
            'access_type'     => 'view_only',
            'wait_days'       => 30,
            'encrypted_key'   => null,
            'granted_at'      => null,
        ];
    }
}
