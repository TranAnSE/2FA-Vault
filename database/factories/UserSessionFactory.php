<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserSession>
 */
class UserSessionFactory extends Factory
{
    protected $model = UserSession::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'user_id' => User::factory(),
            'token_id' => $this->faker->unique()->sha1,
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'last_active_at' => now(),
            'created_at' => now(),
        ];
    }

    /**
     * Associate the session with a specific user.
     */
    public function forUser(User $user)
    {
        return $this->state(function (array $attributes) use ($user) {
            return ['user_id' => $user->id];
        });
    }
}
