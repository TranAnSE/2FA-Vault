<?php

namespace App\Extensions;

use Illuminate\Auth\EloquentUserProvider;

/**
 * Password-login policy provider.
 *
 * Enforces the useWebauthnOnly user preference GLOBALLY: when the preference
 * is on, email+password validation is rejected on every surface that goes
 * through the `eloquent` provider driver -- web login AND Passport's
 * api-guard password grant (plan 261009-passkeys-migration, red-team H4).
 *
 * This replaces the former WebauthnTwoFAuthUserProvider (which extended the
 * abandoned laragear package provider) while preserving its exact enforcement
 * semantics. The WebAuthn assertion path no longer runs through a user
 * provider at all: passkey logins validate via the VerifyPasskey action in
 * WebAuthnLoginController and call Auth::login() directly.
 *
 * The device-lost recovery flow bypasses this rejection by swapping in a
 * stock EloquentUserProvider before Auth::attempt (WebAuthnRecoveryController),
 * mirroring the previous behavior.
 */
class TwoFAuthUserProvider extends EloquentUserProvider
{
    /**
     * Validate a user against the given credentials.
     *
     * @param  \App\Models\User  $user
     */
    public function validateCredentials($user, array $credentials) : bool
    {
        // If the user disabled the password fallback, reject password login.
        return $user->preferences['useWebauthnOnly'] == false && parent::validateCredentials($user, $credentials);
    }
}
