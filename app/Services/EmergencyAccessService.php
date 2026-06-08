<?php

namespace App\Services;

use App\Models\EmergencyAccessRequest;
use App\Models\EmergencyContact;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class EmergencyAccessService
{
    const MAX_CONTACTS = 5;

    public function designateContact(User $owner, string $email, int $waitDays, string $accessType): EmergencyContact
    {
        if ($owner->email === $email) {
            throw new \InvalidArgumentException('You cannot designate yourself as an emergency contact.');
        }

        $count = EmergencyContact::where('owner_id', $owner->id)
            ->whereIn('status', ['pending', 'confirmed', 'active'])
            ->count();

        if ($count >= self::MAX_CONTACTS) {
            throw new \OverflowException('Maximum of ' . self::MAX_CONTACTS . ' emergency contacts allowed.');
        }

        $trustedUser = User::where('email', $email)->first();

        $contact = EmergencyContact::updateOrCreate(
            ['owner_id' => $owner->id, 'email' => $email],
            [
                'trusted_user_id' => $trustedUser?->id,
                'wait_days'       => $waitDays,
                'access_type'     => $accessType,
                'status'          => $trustedUser ? 'confirmed' : 'pending',
            ]
        );

        Log::info('Emergency contact designated', ['owner' => $owner->id, 'contact_email' => $email]);

        return $contact;
    }

    public function requestAccess(EmergencyContact $contact): EmergencyAccessRequest
    {
        if (!in_array($contact->status, ['confirmed', 'active'])) {
            throw new \RuntimeException('This emergency contact is not active.');
        }

        return EmergencyAccessRequest::create([
            'contact_id'   => $contact->id,
            'requester_id' => $contact->trusted_user_id,
            'status'       => 'pending',
            'requested_at' => now(),
        ]);
    }

    public function approveRequest(EmergencyAccessRequest $request, ?string $encryptedKey = null): void
    {
        $request->update([
            'status'       => 'approved',
            'responded_at' => now(),
            'granted_at'   => now(),
        ]);

        $request->contact->update([
            'status'        => 'active',
            'granted_at'    => now(),
            'encrypted_key' => $encryptedKey,
        ]);
    }

    public function denyRequest(EmergencyAccessRequest $request): void
    {
        $request->update(['status' => 'denied', 'responded_at' => now()]);
    }

    public function revokeContact(EmergencyContact $contact): void
    {
        $contact->update(['status' => 'revoked', 'encrypted_key' => null, 'granted_at' => null]);
        $contact->accessRequests()->where('status', 'pending')->update(['status' => 'denied', 'responded_at' => now()]);

        Log::info('Emergency contact revoked', ['contact_id' => $contact->id]);
    }

    /**
     * Auto-grant expired pending requests (scheduled daily).
     */
    public function processExpiredRequests(): int
    {
        $processed = 0;
        EmergencyAccessRequest::where('status', 'pending')
            ->with('contact')
            ->get()
            ->each(function (EmergencyAccessRequest $request) use (&$processed) {
                $waitDays = $request->contact->wait_days;
                if (now()->diffInDays($request->requested_at) >= $waitDays) {
                    $request->update([
                        'status'    => 'auto_granted',
                        'granted_at'=> now(),
                    ]);
                    $request->contact->update([
                        'status'     => 'active',
                        'granted_at' => now(),
                    ]);
                    $processed++;
                    Log::info('Emergency access auto-granted', ['contact_id' => $request->contact_id]);
                }
            });

        return $processed;
    }

    /**
     * Check all confirmed contacts for owner inactivity (dead man's switch).
     */
    public function checkDeadMansSwitch(): int
    {
        $triggered = 0;
        EmergencyContact::where('status', 'confirmed')
            ->with('owner')
            ->chunk(100, function ($contacts) use (&$triggered) {
                foreach ($contacts as $contact) {
                    $owner       = $contact->owner;
                    $lastSeen    = $owner->last_seen_at ?? $owner->created_at;
                    $inactiveDays = (int) now()->diffInDays($lastSeen);

                    if ($inactiveDays >= $contact->wait_days) {
                        // Auto-create a request and immediately grant it
                        $request = EmergencyAccessRequest::firstOrCreate(
                            ['contact_id' => $contact->id, 'status' => 'pending'],
                            ['requester_id' => $contact->trusted_user_id, 'requested_at' => now()]
                        );

                        if ($request->wasRecentlyCreated) {
                            $request->update(['status' => 'auto_granted', 'granted_at' => now()]);
                            $contact->update(['status' => 'active', 'granted_at' => now()]);
                            $triggered++;
                            Log::info("Dead man's switch triggered", ['contact_id' => $contact->id, 'owner' => $owner->id]);
                        }
                    }
                }
            });

        return $triggered;
    }
}
