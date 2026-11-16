<?php

namespace App\Http\Controllers;

use App\Http\Requests\PrfEnrollRequest;
use App\Http\Requests\PrfRewrapRequest;
use App\Models\PasskeyPrfCredential;
use App\Models\User;
use App\Services\EncryptionService;
use App\Support\Passkeys\PrfEnrollChallenge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\Exception\WebauthnException;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * PRF vault-unlock API (plan 261114-2101, phase 2).
 *
 * Stores and serves the per-credential wrapped RSA keyset. ZERO-KNOWLEDGE:
 * the server NEVER receives the PRF output, the vault key, or the master
 * password — only the salt and three opaque wrapped blobs per credential.
 *
 * SECURITY INVARIANT (D2, red-team Critical F1): no enrollment mutation
 * trusts anything but possession of the passkey. Enrollment is stored ONLY
 * after the server verifies an assertion signature against the stored
 * credential (the same VerifyPasskey machinery as login), so a hijacked
 * session or stolen PAT cannot plant a public_key — whose exploitation
 * path would be a vault-key exfiltration oracle via rotation re-wrap.
 */
class PrfUnlockController extends Controller
{
    public function __construct(protected EncryptionService $encryptionService) {}

    /**
     * GET encryption/prf/context — the unlock flow's single fetch (D7).
     *
     * Returns the client-side PRF salt plus the E2EE fields the unlock flow
     * needs (mirroring /encryption/info so one fetch suffices), and the
     * user's enrollments. The prf_salt is created lazily and ATOMICALLY on
     * first read (red-team M10): `UPDATE ... WHERE prf_salt IS NULL` then a
     * fresh read, so two racing tabs converge on one salt; there is no
     * dedicated init endpoint to abuse.
     */
    public function context(Request $request) : JsonResponse
    {
        $this->guard($request);
        $this->rateLimit($request, 'prf-context', 30, 60);

        $user = $request->user();
        $user->refresh();

        if ($user->prf_salt === null) {
            // One UPDATE guarded on NULL: two racers converge, only one writes.
            User::whereKey($user->getKey())
                ->whereNull('prf_salt')
                ->update(['prf_salt' => base64_encode(random_bytes(32))]);

            $user->refresh();
        }

        $enrollments = PasskeyPrfCredential::query()
            ->where('user_id', $user->getKey())
            ->with('passkey:id,credential_id')
            ->get()
            ->map(fn (PasskeyPrfCredential $e) => [
                'credential_id'       => $e->passkey->credential_id,
                'public_key'          => $e->public_key,
                'wrapped_private_key' => $e->wrapped_private_key,
                'wrapped_vault_key'   => $e->wrapped_vault_key,
            ]);

        return response()->json([
            'prf_salt'              => $user->prf_salt,
            'encryption_salt'       => $user->encryption_salt,
            'encryption_test_value' => $user->encryption_test_value,
            'enrollments'           => $enrollments,
        ]);
    }

    /**
     * POST encryption/prf/enroll/options — assertion options for ONE credential.
     *
     * The enroll possession proof (D2) rides a server-generated challenge
     * scoped to one credential. The serialized options are stored in the
     * cache (single-use, TTL-gated) under the user's id — the api.v1 group
     * runs without session middleware, so the web-route OptionsSession is
     * not available here; PrfEnrollChallenge provides the same M2 semantics.
     */
    public function enrollOptions(Request $request) : JsonResponse
    {
        $this->guard($request);
        $this->rateLimit($request, 'prf-enroll-options', 10, 60);

        $validated = $request->validate(['credential_id' => ['required', 'string', 'max:255']]);

        $user = $request->user();

        $passkey = $user->passkeys()->where('credential_id', $validated['credential_id'])->first();

        abort_unless($passkey instanceof Passkey, 404);

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: Passkeys::relyingPartyId(),
            allowCredentials: [PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                Base64UrlSafe::decodeNoPadding($passkey->credential_id)
            )],
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: Passkeys::timeout(),
        );

        // Per-credential key (review fix): two parallel enroll ceremonies for
        // different credentials of one user no longer clobber each other's
        // stored options (last-write-wins → spurious 401 on one ceremony).
        PrfEnrollChallenge::put($user->getKey(), $validated['credential_id'], WebAuthn::toJson($options));

        return response()->json(WebAuthn::toBrowserArray($options), 200);
    }

    /**
     * POST encryption/prf/enroll — verified-assertion enrollment (D2).
     *
     * The assertion signature is verified against the stored passkey BEFORE
     * anything is stored: possession of the passkey is the only trusted
     * fact. Upsert by passkey (re-enroll replaces blobs atomically; the
     * assertion requirement makes replacement safe). A hijacked session
     * without the physical authenticator cannot enroll or replace blobs.
     *
     * Note: this deliberately does NOT log anyone in and does NOT consume
     * the login options session (dedicated cache-backed challenge).
     */
    public function enroll(PrfEnrollRequest $request, VerifyPasskey $verify) : Response|JsonResponse
    {
        $this->guard($request);
        $this->rateLimit($request, 'prf-enroll', 3, 60);

        $user = $request->user();

        $passkey = $user->passkeys()->where('credential_id', $request->validated('credential_id'))->first();

        abort_unless($passkey instanceof Passkey, 404);

        try {
            $options = WebAuthn::fromJson(
                PrfEnrollChallenge::pull($user->getKey(), $request->validated('credential_id')),
                PublicKeyCredentialRequestOptions::class
            );

            $verify($request->credential(), $options, $user);
        } catch (InvalidPasskeyException|WebauthnException|\RangeException) {
            // Assertion verification failure (signature/counter/ownership).
            // Order matters: InvalidPasskeyException extends ValidationException,
            // so this clause must precede the expiry clause below.
            return response()->json(['message' => __('Assertion verification failed. Please try again.')], 422);
        } catch (ValidationException) {
            // Expired or missing challenge options — same ceremony-failure
            // semantics as the M2 TTL gate on login (401 shape).
            return response()->json(['message' => __('Passkey session expired. Please try again.')], 401);
        }

        $validated = $request->validated();

        $disabled = false;

        DB::transaction(function () use ($user, $passkey, $validated, &$disabled) {
            // Fresh E2EE-enabled check inside the transaction (review fix):
            // guard() read it before the ceremony window; a concurrent
            // disable+flush would otherwise leave an orphan keyset behind.
            // The user-row lock serializes against disable/setup writers.
            $enabled = (bool) User::query()->whereKey($user->getKey())->lockForUpdate()->value('encryption_enabled');

            if (! $enabled) {
                $disabled = true;

                return;
            }

            // Atomic upsert: re-enroll replaces the blobs in place. The
            // model is only ever written with server-resolved keys — no
            // request-controlled mass assignment exists.
            PasskeyPrfCredential::updateOrCreate(
                ['user_id' => $user->getKey(), 'passkey_id' => $passkey->id],
                [
                    'public_key'          => $validated['public_key'],
                    'wrapped_private_key' => $validated['wrapped_private_key'],
                    'wrapped_vault_key'   => $validated['wrapped_vault_key'],
                ]
            );
        });

        if ($disabled) {
            abort(400, 'Encryption is not enabled');
        }

        Log::info('PRF enrollment stored', ['user_id' => $user->id]);

        return response()->json(null, 204);
    }

    /**
     * POST encryption/prf/rewrap — rotation re-wrap (D7/D9).
     *
     * Updates wrapped_vault_key only, all rows in one transaction.
     * `expected_encryption_salt_hash` is the stale-writer guard: 409 when
     * the client's salt view is stale (racing tab / interleaved rotation),
     * 422 listing unknown credential ids (all-or-nothing: no partial
     * updates, then list).
     */
    public function rewrap(PrfRewrapRequest $request) : JsonResponse
    {
        $this->guard($request);
        // 2/hour: one rotation + one 409-retry (stale salt view). A client
        // retry after 409 consumes the second attempt by design.
        $this->rateLimit($request, 'prf-rewrap', 2, 3600);

        $user = $request->user();

        if (! hash_equals(hash('sha256', $user->encryption_salt), $request->validated('expected_encryption_salt_hash'))) {
            return response()->json(['message' => 'Encryption salt changed since your last view; reload and retry.'], 409);
        }

        /** @var list<array{credential_id: string, wrapped_vault_key: string}> $wrappings */
        $wrappings = $request->validated('wrappings');

        // All-or-nothing: resolve every wrapping to an owned enrollment
        // BEFORE writing, so an unknown id yields 422 with zero rows touched.
        $enrollments = PasskeyPrfCredential::query()
            ->where('user_id', $user->getKey())
            ->whereHas('passkey', fn ($q) => $q->whereIn('credential_id', collect($wrappings)->pluck('credential_id')))
            ->with('passkey:id,credential_id')
            ->get();

        $resolved = $enrollments->keyBy('passkey.credential_id');

        $unknown = collect($wrappings)->pluck('credential_id')->diff($resolved->keys());

        if ($unknown->isNotEmpty()) {
            return response()->json([
                'message'                => 'Unknown credential ids in wrappings',
                'unknown_credential_ids' => $unknown->values()->all(),
            ], 422);
        }

        $staleGuard         = false;
        $encryptionDisabled = false;

        DB::transaction(function () use ($user, $resolved, $wrappings, $request, &$staleGuard, &$encryptionDisabled) {
            // In-transaction stale-writer re-check under a user-row lock
            // (review fix, TOCTOU): the pre-transaction check above reads
            // without a lock; a rotation or disable committing between it
            // and the write would still land wrapped_vault_key rows under
            // a superseded vault key. The lock serializes against those
            // writers — and against a concurrent rewrap from another tab.
            /** @var User $lockedUser */
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $lockedUser->encryption_enabled) {
                $encryptionDisabled = true;

                return;
            }

            if (! hash_equals(hash('sha256', (string) $lockedUser->encryption_salt), $request->validated('expected_encryption_salt_hash'))) {
                $staleGuard = true;

                return;
            }

            foreach ($wrappings as $w) {
                $enrollment                    = $resolved->get($w['credential_id']);
                $enrollment->wrapped_vault_key = $w['wrapped_vault_key'];
                $enrollment->save();
            }
        });

        if ($encryptionDisabled) {
            abort(400, 'Encryption is not enabled');
        }

        if ($staleGuard) {
            return response()->json(['message' => 'Encryption salt changed since your last view; reload and retry.'], 409);
        }

        Log::info('PRF rewrap applied', ['user_id' => $user->id]);

        return response()->json(null, 204);
    }

    /**
     * DELETE encryption/prf/{credentialId} — remove one enrollment.
     *
     * Resolved via the authenticated user's passkeys; foreign/unknown → 404.
     * (Passkey revoke/recovery/user deletion cover the rest via FK cascade.)
     */
    public function destroy(Request $request, string $credentialId) : JsonResponse
    {
        $this->guard($request);
        $this->rateLimit($request, 'prf-delete', 10, 60);

        $deleted = PasskeyPrfCredential::query()
            ->where('user_id', $request->user()->getKey())
            ->whereHas('passkey', fn ($q) => $q->where('credential_id', $credentialId))
            ->delete();

        abort_unless($deleted > 0, 404);

        Log::info('PRF enrollment deleted', ['user_id' => $request->user()->id]);

        return response()->json(null, 204);
    }

    /**
     * Shared guards: SSO-only gate (403, family pattern) + E2EE-enabled
     * guard (400, red-team F8 — kills pre-load attacks). Gate first.
     */
    protected function guard(Request $request) : void
    {
        if (Gate::denies('manage-webauthn-credentials')) {
            throw new AccessDeniedHttpException(__('error.unsupported_with_sso_only'));
        }

        if (! $this->encryptionService->isEncryptionEnabled($request->user())) {
            abort(400, 'Encryption is not enabled');
        }
    }

    protected function rateLimit(Request $request, string $bucket, int $maxAttempts, int $decaySeconds) : void
    {
        // Family pattern: skipped in console-driven automated tests.
        if (app()->runningInConsole()) {
            return;
        }

        $key = $bucket . ':' . $request->user()->getKey();

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);
            abort(429, "Too many attempts. Please try again in {$seconds} seconds.");
        }

        RateLimiter::hit($key, $decaySeconds);
    }
}
