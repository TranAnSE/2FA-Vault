<?php

namespace Database\Factories;

use App\Models\OtpLog;
use App\Models\TwoFAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OtpLog>
 */
class OtpLogFactory extends Factory
{
    protected $model = OtpLog::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        $user = User::factory()->create();

        return [
            'requester_id'   => $user->id,
            'owner_id'       => $user->id,
            'twofaccount_id' => TwoFAccount::factory(),
            'otp_type'       => 'totp',
            'counter'        => null,
            'ip_address'     => $this->faker->ipv4(),
            'user_agent'     => $this->faker->userAgent(),
            'generated_at'   => now(),
        ];
    }
}
