<?php

namespace Database\Factories;

use App\Models\SecureNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SecureNote>
 */
class SecureNoteFactory extends Factory
{
    protected $model = SecureNote::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'user_id' => User::factory(),
            'title' => $this->faker->sentence(3),
            'content' => $this->faker->paragraph(3),
            'content_type' => 'plain',
            'is_pinned' => false,
        ];
    }

    /**
     * Associate the note with a specific user.
     */
    public function forUser(User $user)
    {
        return $this->state(function (array $attributes) use ($user) {
            return ['user_id' => $user->id];
        });
    }
}
