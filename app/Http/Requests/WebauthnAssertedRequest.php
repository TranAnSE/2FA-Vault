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
        return [
            'email' => ['required', 'email'],
            'id' => ['required', 'string'],
            'rawId' => ['required', 'string'],
            'type' => ['required', 'string', 'in:public-key'],
            'response' => ['required', 'array'],
            'response.clientDataJSON' => ['required', 'string'],
            'response.authenticatorData' => ['required', 'string'],
            'response.signature' => ['required', 'string'],
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
