<?php

namespace Database\Factories;

use App\Enums\PersonalAction;
use App\Models\PersonalActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PersonalActivityLog>
 */
class PersonalActivityLogFactory extends Factory
{
    protected $model = PersonalActivityLog::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'user_id' => User::factory(),
            'action' => PersonalAction::LOGIN->value,
            'metadata' => null,
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'target_account_id' => null,
            'created_at' => now(),
        ];
    }

    /**
     * Associate the log with a specific user.
     */
    public function forUser(User $user)
    {
        return $this->state(function (array $attributes) use ($user) {
            return ['user_id' => $user->id];
        });
    }
}
