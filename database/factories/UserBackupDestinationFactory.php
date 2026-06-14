<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserBackupDestination;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserBackupDestination>
 */
class UserBackupDestinationFactory extends Factory
{
    protected $model = UserBackupDestination::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'user_id' => User::factory(),
            'label' => $this->faker->unique()->word() . ' backup',
            'type' => 'local',
            'config' => ['path' => 'backups'],
            'is_active' => true,
            'last_run_at' => null,
            'last_run_status' => null,
        ];
    }

    /**
     * Associate the destination with a specific user.
     */
    public function forUser(User $user)
    {
        return $this->state(function (array $attributes) use ($user) {
            return ['user_id' => $user->id];
        });
    }

    /**
     * Mark the destination as inactive.
     */
    public function inactive()
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
