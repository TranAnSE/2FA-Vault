<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * PRF rewrap request (plan 261114-2101, D7/D9).
 *
 * Rotation path only: re-encapsulates the vault key under each enrolled
 * credential's stored public key — a public-key-only operation, no PRF
 * ceremony. `expected_encryption_salt_hash` is the stale-writer guard: the
 * server 409s when the client's view of encryption_salt is no longer the
 * stored one (multi-tab rotation, interleaved writers).
 */
class PrfRewrapRequest extends FormRequest
{
    public function authorize() : bool
    {
        // Same gate-as-authorize pattern as PrfEnrollRequest (SSO-only 403
        // before validation). The E2EE-enabled 400 guard lives in the
        // controller.
        return (bool) $this->user() && Gate::allows('manage-webauthn-credentials');
    }

    public function rules() : array
    {
        return [
            'expected_encryption_salt_hash' => ['required', 'string', 'size:64'],
            'wrappings'                     => ['required', 'array', 'min:1', 'max:100'],
            'wrappings.*.credential_id'     => ['required', 'string', 'max:255'],
            'wrappings.*.wrapped_vault_key' => ['required', 'string', 'max:16384'],
        ];
    }
}
