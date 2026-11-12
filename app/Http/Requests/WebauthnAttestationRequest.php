<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Registration-options request (fork-owned; replaces the laragear
 * AttestationRequest base, plan 261009-passkeys-migration phase 3 R6).
 *
 * Carries the same SSO-only gate as the other manage endpoints (A7): the
 * registration path cannot be used to bypass it.
 */
class WebauthnAttestationRequest extends FormRequest
{
    /**
     * Authorize the request against the manage-webauthn-credentials gate.
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

    /**
     * Validation is not applicable to the options request.
     */
    public function rules() : array
    {
        return [];
    }
}
