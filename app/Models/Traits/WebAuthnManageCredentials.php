<?php

namespace App\Models\Traits;

use App\Notifications\WebauthnRecoveryNotification;
use Laravel\Passkeys\Passkey;

/**
 * Manage helpers for the user's passkey credentials
 * (laravel/passkeys `passkeys` table, plan 261009-passkeys-migration).
 *
 * Retargeted from the former laragear webAuthnCredentials() relation:
 * rename/delete are keyed on credential_id (delete-only policy, D5 -- no
 * disabled flag exists in the passkeys schema).
 */
trait WebAuthnManageCredentials
{
    /**
     * Saves a new name for a given passkey credential.
     *
     * @param  string  $id  the credential_id (base64url, as exposed by the API)
     */
    public function renameCredential(string $id, string $alias) : bool
    {
        return boolval($this->passkeys()->where('credential_id', $id)->update(['name' => $alias]));
    }

    /**
     * Removes one or more credentials previously registered.
     *
     * @param  string|array  $id  credential_id(s)
     */
    public function flushCredential($id) : void
    {
        $this->passkeys()->whereIn('credential_id', (array) $id)->delete();
    }

    /**
     * Removes every passkey of the user.
     */
    public function flushCredentials() : void
    {
        $this->passkeys()->delete();
    }

    /**
     * Sends a webauthn recovery email to the user.
     */
    public function sendWebauthnRecoveryNotification(string $token) : void
    {
        try {
            $this->notify(new WebauthnRecoveryNotification($token));
        }
        catch(\Throwable) {
            // Nothing to do here, LogNotificationListener will log error details
        }
    }
}
