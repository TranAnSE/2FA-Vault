<?php

namespace Database\Factories;

use App\Models\EmergencyAccessRequest;
use App\Models\EmergencyContact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmergencyAccessRequestFactory extends Factory
{
    protected $model = EmergencyAccessRequest::class;

    public function definition(): array
    {
        return [
            'contact_id'   => EmergencyContact::factory(),
            'requester_id' => User::factory(),
            'status'       => 'pending',
            'requested_at' => now(),
            'responded_at' => null,
            'granted_at'   => null,
        ];
    }
}
