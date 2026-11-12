<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\WebauthnAttestationRequest;
use App\Http\Requests\WebauthnAttestedRequest;
use App\Support\Passkeys\OptionsSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Support\WebAuthn;
use Webauthn\Exception\WebauthnException;
use Webauthn\PublicKeyCredentialCreationOptions;

class WebAuthnRegisterController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | WebAuthn (passkey) Register Controller
    |--------------------------------------------------------------------------
    |
    | Serves the /webauthn/register contract (plan 261009-passkeys-migration
    | D3) on top of the laravel/passkeys actions.
    |
    | Ceremony policy (D7): the package emits userVerification REQUIRED and
    | residentKey REQUIRED -- adopted as-is, no fork Action subclasses. The
    | former config('webauthn.user_verification') switch was dead code (the
    | config key was never defined; effective behavior was browser-default
    | preferred) and is intentionally not reproduced. Security keys without a
    | configured PIN stop working until the user sets one: documented in the
    | v1.6.0 release note.
    |
    */

    /**
     * Returns a challenge to be verified by the user device.
     */
    public function options(WebauthnAttestationRequest $request, GenerateRegistrationOptions $generate) : JsonResponse
    {
        $options = $generate($request->user());

        OptionsSession::put($request, OptionsSession::REGISTER_KEY, WebAuthn::toJson($options));

        return response()->json(WebAuthn::toBrowserArray($options));
    }

    /**
     * Registers a device for further WebAuthn authentication.
     *
     * @throws ValidationException on ceremony failure
     *                             (malformed or mismatched attestation, expired options) -- the
     *                             SPA surfaces the 422 payload generically.
     */
    public function register(WebauthnAttestedRequest $request, StorePasskey $store) : Response
    {
        $options = WebAuthn::fromJson(
            OptionsSession::pull($request, OptionsSession::REGISTER_KEY),
            PublicKeyCredentialCreationOptions::class
        );

        try {
            $store(
                $request->user(),
                $request->deviceName() ?? 'Device ' . ($request->user()->passkeys()->count() + 1),
                $request->credential(),
                $options
            );
        } catch (InvalidPasskeyException|WebauthnException|\RangeException) {
            // InvalidPasskeyException: duplicate credential id (re-adding the
            // same security key) or unrecognized credential -- the vendor
            // message stays untranslated, so rethrow with the lang key.
            // WebauthnException: ceremony rejection (signature, origin,
            // challenge, COSE parse). \RangeException: bare base64url decode
            // failures that escape a few vendor denormalizers. All 422s.
            throw ValidationException::withMessages([
                'credential' => __('error.passkey_not_verified'),
            ]);
        }

        Log::info(sprintf('User ID #%s registered a new security device', $request->user()->id));

        return response()->noContent();
    }
}
