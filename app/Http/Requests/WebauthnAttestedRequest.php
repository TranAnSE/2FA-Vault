<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Laravel\Passkeys\Support\WebAuthn;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Webauthn\PublicKeyCredential;

/**
 * Registration-attestation request (fork-owned; replaces the laragear
 * AttestedRequest base, plan 261009-passkeys-migration phase 3 R6).
 *
 * The SPA posts the raw browser credential JSON at the top level; an optional
 * device name may accompany it.
 */
class WebauthnAttestedRequest extends FormRequest
{
    /**
     * Same SSO-only gate as the other manage endpoints (A7): the registration
     * path must not bypass the policy.
     */
    public function authorize() : bool
    {
        return (bool) $this->user() && Gate::allows('manage-webauthn-credentials');
    }

    /**
     * Handle a failed authorization attempt (kept for the framework flow).
     */
    protected function failedAuthorization()
    {
        throw new AccessDeniedHttpException(__('error.unsupported_with_sso_only'));
    }

    public function rules() : array
    {
        // Strict base64url on the binary members (see WebauthnAssertedRequest).
        $b64url = ['required', 'string', 'regex:/^[A-Za-z0-9_-]+$/'];

        return [
            'id'                         => $b64url,
            'rawId'                      => $b64url,
            'type'                       => ['required', 'string', 'in:public-key'],
            'response'                   => ['required', 'array'],
            'response.clientDataJSON'    => $b64url,
            'response.attestationObject' => $b64url,
            'name'                       => ['sometimes', 'string', 'max:255'],
        ];
    }

    /**
     * The deserialized public key credential.
     */
    public function credential() : PublicKeyCredential
    {
        return WebAuthn::fromJson(
            json_encode($this->except('name'), JSON_THROW_ON_ERROR),
            PublicKeyCredential::class
        );
    }

    /**
     * Optional device name for the new passkey.
     */
    public function deviceName() : ?string
    {
        return $this->input('name');
    }
}
