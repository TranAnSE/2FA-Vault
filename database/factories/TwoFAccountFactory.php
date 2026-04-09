<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use ParagonIE\ConstantTime\Base32;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TwoFAccount>
 */
class TwoFAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        $account = $this->faker->safeEmail();
        $service = $this->faker->domainName();
        $secret = Base32::encodeUpper($this->faker->regexify('[A-Z0-9]{8}'));

        return [
            'group_id' => null,
            'otp_type' => 'totp',
            'account' => $account,
            'service' => $service,
            'secret' => $secret,
            'algorithm' => 'sha1',
            'digits' => 6,
            'period' => 30,
            'legacy_uri' => 'otpauth://hotp/' . $service . ':' . $account . '?secret=' . $secret . '&issuer=' . $service,
            'icon' => '',
            'encrypted' => false,
        ];
    }

    public function encrypted(?string $payload = null)
    {
        return $this->state(function (array $attributes) use ($payload) {
            return [
                'encrypted' => true,
                'secret' => $payload ?? json_encode([
                    'ciphertext' => base64_encode(random_bytes(32)),
                    'iv' => base64_encode(random_bytes(12)),
                    'authTag' => base64_encode(random_bytes(16)),
                ]),
            ];
        });
    }

    public function forUser(User $user)
    {
        return $this->state(function (array $attributes) use ($user) {
            return [
                'user_id' => $user->id,
            ];
        });
    }

    public function inGroup(Group $group)
    {
        return $this->state(function (array $attributes) use ($group) {
            return [
                'group_id' => $group->id,
                'user_id' => $group->user_id,
            ];
        });
    }

    public function duplicateOf(string $service, string $account)
    {
        return $this->state(function (array $attributes) use ($service, $account) {
            return [
                'service' => $service,
                'account' => $account,
            ];
        });
    }
}
