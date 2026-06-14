<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserInvitation>
 */
class UserInvitationFactory extends Factory
{
    protected $model = UserInvitation::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'email' => $this->faker->unique()->safeEmail(),
            'token' => Str::random(64),
            'invited_by_id' => null,
            'role' => 'user',
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
        ];
    }

    /**
     * Mark the invitation as already accepted.
     */
    public function accepted()
    {
        return $this->state(fn (array $attributes) => ['accepted_at' => now()->subDay()]);
    }

    /**
     * Mark the invitation as expired.
     */
    public function expired()
    {
        return $this->state(fn (array $attributes) => ['expires_at' => now()->subDays(2), 'accepted_at' => null]);
    }
}
