<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Laravel\Passkeys\Support\WebAuthn;
use Webauthn\PublicKeyCredential;

/**
 * PRF enrollment request (plan 261114-2101, D2/D7).
 *
 * Carries BOTH the server-challenged assertion (possession proof — the
 * server verifies the assertion signature against the stored passkey before
 * storing anything, red-team Critical F1) and the client-produced wrapped
 * keyset blobs.
 *
 * The binary members are strict base64url (WebAuthn Level 3 JSON); rejecting
 * non-base64url characters here converts malformed payloads into clean 422s
 * before any deserialization.
 */
class PrfEnrollRequest extends FormRequest
{
    public function authorize() : bool
    {
        // The manage-webauthn-credentials gate is enforced HERE (before
        // validation), matching WebauthnAttestationRequest: SSO-only
        // instances get a 403 regardless of payload shape. The E2EE-enabled
        // 400 guard lives in the controller (family pattern).
        return (bool) $this->user() && Gate::allows('manage-webauthn-credentials');
    }

    public function rules() : array
    {
        $b64url = ['required', 'string', 'regex:/^[A-Za-z0-9_-]+$/'];

        return [
            'credential_id'                        => ['required', 'string', 'max:255'],
            'assertion'                            => ['required', 'array'],
            'assertion.id'                         => $b64url,
            'assertion.rawId'                      => $b64url,
            'assertion.type'                       => ['required', 'string', 'in:public-key'],
            'assertion.response'                   => ['required', 'array'],
            'assertion.response.clientDataJSON'    => $b64url,
            'assertion.response.authenticatorData' => $b64url,
            'assertion.response.signature'         => $b64url,
            // Wrapped blobs (SPKI b64 ≈ 0.6 KB, wrapped vault key ≈ 0.4 KB —
            // generous 16 KB ceiling each).
            'public_key'          => ['required', 'string', 'max:16384'],
            'wrapped_private_key' => ['required', 'string', 'max:16384'],
            'wrapped_vault_key'   => ['required', 'string', 'max:16384'],
        ];
    }

    /**
     * The deserialized public key credential (assertion ceremony object).
     */
    public function credential() : PublicKeyCredential
    {
        return WebAuthn::fromJson(
            json_encode($this->validated()['assertion'], JSON_THROW_ON_ERROR),
            PublicKeyCredential::class
        );
    }
}
