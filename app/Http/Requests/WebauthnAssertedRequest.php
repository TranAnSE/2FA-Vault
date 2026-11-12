<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Laravel\Passkeys\Support\WebAuthn;
use Webauthn\PublicKeyCredential;

/**
 * Login-assertion request (fork-owned; replaces the laragear AssertedRequest
 * base, plan 261009-passkeys-migration phase 3 R6).
 *
 * The SPA posts the raw browser credential JSON at the top level with the
 * email of the account signing in -- the shape is deserialized directly by
 * the webauthn-lib denormalizer (spike-proven).
 */
class WebauthnAssertedRequest extends FormRequest
{
    public function authorize() : bool
    {
        return true;
    }

    public function rules() : array
    {
        // The binary members are strict base64url (WebAuthn Level 3 JSON);
        // rejecting non-base64url characters here converts malformed
        // payloads into clean 422s before any deserialization (webauthn-lib
        // throws bare \RangeException for some of them).
        $b64url = ['required', 'string', 'regex:/^[A-Za-z0-9_-]+$/'];

        return [
            'email'                      => ['required', 'email'],
            'id'                         => $b64url,
            'rawId'                      => $b64url,
            'type'                       => ['required', 'string', 'in:public-key'],
            'response'                   => ['required', 'array'],
            'response.clientDataJSON'    => $b64url,
            'response.authenticatorData' => $b64url,
            'response.signature'         => $b64url,
        ];
    }

    /**
     * The deserialized public key credential.
     */
    public function credential() : PublicKeyCredential
    {
        return WebAuthn::fromJson(
            json_encode($this->except('email'), JSON_THROW_ON_ERROR),
            PublicKeyCredential::class
        );
    }
}
