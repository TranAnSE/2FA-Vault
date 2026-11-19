<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\WebauthnRenameRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Passkeys\Passkey;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class WebAuthnManageController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | WebAuthn (passkey) Manage Controller
    |--------------------------------------------------------------------------
    |
    | Lists, renames and deletes the user's passkeys (laravel/passkeys table).
    |
    | Response contract (red-team C7, plan 261009 phase 3 R4): the passkeys
    | schema differs from the former laragear webauthn_credentials, so the
    | list is NOT byte-identical. The field mapping below keeps the SPA's
    | existing readers working unchanged: id := credential_id (rename/delete
    | URLs), alias := name (device label). Fields unavailable in the new
    | schema (origin, attestation_format, disabled_at) are omitted;
    | last_used_at is new.
    |
    */

    /**
     * List all registered passkey credentials.
     */
    public function index(Request $request) : JsonResponse
    {
        if (Gate::denies('manage-webauthn-credentials')) {
            throw new AccessDeniedHttpException(__('error.unsupported_with_sso_only'));
        }

        $credentials = $request->user()->passkeys()
            ->orderBy('created_at')
            ->get()
            ->map(fn ($passkey) => $this->present($passkey));

        return response()->json($credentials, 200);
    }

    /**
     * Rename a passkey credential.
     */
    public function rename(WebauthnRenameRequest $request, string $credential) : JsonResponse
    {
        // Same SSO-only gate as index() and delete() so the rename path
        // cannot be used to bypass it (A7).
        if (Gate::denies('manage-webauthn-credentials')) {
            throw new AccessDeniedHttpException(__('error.unsupported_with_sso_only'));
        }

        $validated = $request->validated();

        abort_if(! $request->user()->renameCredential($credential, $validated['name']), 404);

        return response()->json([
            'name' => $validated['name'],
        ], 200);
    }

    /**
     * Remove the specified credential from storage.
     */
    public function delete(Request $request, string $credential) : JsonResponse
    {
        Log::info('Deletion of security device requested');

        if (Gate::denies('manage-webauthn-credentials')) {
            throw new AccessDeniedHttpException(__('error.unsupported_with_sso_only'));
        }

        $user = $request->user();
        $user->flushCredential($credential);

        // Webauthn user options need to be reset to prevent impossible login when
        // no more registered device exists.
        // See #110. The `preferences->` json-path assignment persists (the
        // preferences attribute is accessor-only — a plain key write on the
        // merged collection never reached save(); exposed by the phase-5
        // bulk-revoke tests, plan 261119-1650).
        if ($user->passkeys()->doesntExist()) {
            $user['preferences->useWebauthnOnly'] = false;
            $user->save();
            Log::notice(sprintf('No more Webauthn credential for user ID #%s, useWebauthnOnly user preference reset to false', $user->id));
        }

        Log::info(sprintf('User ID #%s revoked a security device', $user->id));

        return response()->json(null, 204);
    }

    /**
     * Bulk-revoke security devices (plan 261119-1650 phase 5).
     *
     * Web-family (session + CSRF) batch of delete(): ownership is resolved
     * BEFORE any write so foreign/unknown ids surface in the response as
     * `unknown` — never a silent no-op (red-team F2) — and the whole batch
     * commits in one transaction. The #110 side effect is preserved: when
     * the batch empties the device list, useWebauthnOnly resets to false
     * (red-team P1-D — dropping it would lock the user out).
     */
    public function bulkRevoke(Request $request) : JsonResponse
    {
        Log::info('Bulk revocation of security devices requested');

        if (Gate::denies('manage-webauthn-credentials')) {
            throw new AccessDeniedHttpException(__('error.unsupported_with_sso_only'));
        }

        /** @var array{ids: list<string>} $validated */
        $validated = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();

        // Ownership resolved BEFORE any write; results keep the REQUEST
        // order (DB order would be nondeterministic for the API consumer).
        $requested = collect($validated['ids'])->unique()->values();
        $owned     = $user->passkeys()->whereIn('credential_id', $requested->all())->pluck('credential_id');
        $deleted   = $requested->filter(fn ($id) => $owned->contains($id))->values();
        $unknown   = $requested->reject(fn ($id) => $owned->contains($id))->values();

        DB::transaction(function () use ($user, $deleted) {
            foreach ($deleted as $credentialId) {
                $user->flushCredential($credentialId);
            }

            // Same #110 reset as the per-device delete: an emptied device
            // list must not leave useWebauthnOnly enabled behind.
            if ($user->passkeys()->doesntExist()) {
                $user['preferences->useWebauthnOnly'] = false;
                $user->save();
                Log::notice(sprintf('No more Webauthn credential for user ID #%s, useWebauthnOnly user preference reset to false', $user->id));
            }
        });

        Log::info(sprintf('User ID #%s bulk-revoked %d security device(s)', $user->id, $deleted->count()));

        return response()->json([
            'deleted' => $deleted->all(),
            'unknown' => $unknown->all(),
        ], 200);
    }

    /**
     * The SPA-compatible credential representation (C7 mapping).
     */
    protected function present(Passkey $passkey) : array
    {
        return [
            'id'           => $passkey->credential_id,
            'alias'        => $passkey->name,
            'aaguid'       => $passkey->credential['aaguid'] ?? null,
            'last_used_at' => $passkey->last_used_at?->toISOString(),
        ];
    }
}
