<?php

namespace App\Support\Passkeys;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Support\WebAuthn;

/**
 * Session persistence for WebAuthn ceremony options with a server-side
 * expiry gate.
 *
 * The laravel/passkeys package stores options one-shot in the session but
 * never expires them server-side (spike finding 3, plan 261009 phase 1):
 * the `timeout` in the options JSON only advises the browser. laragear
 * enforced a comparable gate with its 60s server-side Challenge::isValid
 * check, so this replacement keeps the same guarantee (red-team M2): options
 * older than the configured `passkeys.timeout` are rejected even when the
 * session is still alive.
 */
class OptionsSession
{
    // Keys deliberately avoid dots: the session store treats them as array
    // nesting, so a dotted age suffix would overwrite the options payload
    // itself (plan 261009 phase 3, M2).
    public const VERIFY_KEY = 'passkey_verification_options';

    public const REGISTER_KEY = 'passkey_registration_options';

    public const AGED_KEY_SUFFIX = '_stored_at';

    /**
     * Store serialized options with their storage timestamp.
     */
    public static function put(Request $request, string $key, string $serializedOptions) : void
    {
        $request->session()->put($key, $serializedOptions);
        $request->session()->put($key . self::AGED_KEY_SUFFIX, now()->timestamp);
    }

    /**
     * Pull the serialized options, rejecting the ceremony when the options
     * have expired server-side or were never stored.
     *
     * @throws ValidationException when absent or expired
     */
    public static function pull(Request $request, string $key) : string
    {
        $serialized = $request->session()->pull($key);
        $storedAt   = $request->session()->pull($key . self::AGED_KEY_SUFFIX);

        // A missing pair and an aged-out pair are the same ceremony failure.
        // The null checks short-circuit before the timestamp arithmetic runs.
        if (! $serialized || ! $storedAt || now()->timestamp - $storedAt > PasskeysTimeout::seconds()) {
            throw ValidationException::withMessages([
                'credential' => __('Passkey session expired. Please try again.'),
            ]);
        }

        return $serialized;
    }
}
