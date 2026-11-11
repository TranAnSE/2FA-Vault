<?php

namespace App\Http\Controllers\Auth;

use App\Api\v1\Resources\UserResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\WebauthnAssertedRequest;
use App\Models\User;
use App\Support\Passkeys\OptionsSession;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Laravel\Passkeys\Support\WebAuthn;
use Webauthn\Exception\WebauthnException;
use Webauthn\PublicKeyCredentialRequestOptions;

class WebAuthnLoginController extends Controller
{
    use AuthenticatesUsers;

    /**
     * The login throttle.
     *
     * @var int
     */
    protected $maxAttempts;

    /*
    |--------------------------------------------------------------------------
    | WebAuthn (passkey) Login Controller
    |--------------------------------------------------------------------------
    |
    | Serves the /webauthn/* login contract (paths, names, middleware and
    | response shapes preserved, plan 261009-passkeys-migration D3) on top of
    | the laravel/passkeys actions. The assertion is validated by the
    | VerifyPasskey action; the session user is ALWAYS the owner of the
    | verified credential (never the email-resolved user).
    |
    */

    /**
     * Returns the challenge to assertion.
     */
    public function options(Request $request, GenerateVerificationOptions $generate) : JsonResponse
    {
        $validated = $request->validate([
            'email' => [
                'required',
                'email',
            ],
        ]);

        $user = User::whereRaw('email = ?' . (DB::connection()->getDriverName() === 'sqlite' ? ' COLLATE NOCASE' : ''), [strtolower($validated['email'])])->first();

        // Return a 200-shaped generic response for an unknown email instead
        // of a 422 validation failure: the 200-vs-422 difference was a
        // registered-email enumeration oracle (A8). The response body is
        // deliberately identical to what the SPA already handles for failed
        // challenges. The unknown branch time-equalizes with the known one
        // (M7): both run the users query and the options generation, and the
        // unknown branch pads with a randomized sleep to absorb its missing
        // passkeys query. Statistical adequacy asserted in the Phase 6 suite.
        if ($user === null) {
            $this->generateOptions($generate, null);

            usleep(random_int(15000, 35000));

            return response()->json(['message' => __('auth.failed')], 200);
        }

        $options = $this->generateOptions($generate, $user);

        OptionsSession::put($request, OptionsSession::VERIFY_KEY, WebAuthn::toJson($options));

        return response()->json(WebAuthn::toBrowserArray($options), 200);
    }

    /**
     * Generate verification options (scoped to the user's passkeys when a
     * user is given, discoverable otherwise).
     */
    protected function generateOptions(GenerateVerificationOptions $generate, ?User $user) : \Webauthn\PublicKeyCredentialRequestOptions
    {
        return $generate($user);
    }

    /**
     * Log the user in.
     *
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function login(WebauthnAssertedRequest $request, VerifyPasskey $verify)
    {
        Log::info(sprintf('User login via webauthn requested by %s from %s', var_export($request['email'], true), $request->ip()));

        $this->maxAttempts = config('auth.throttle.login');

        // If the class is using the ThrottlesLogins trait, we can automatically throttle
        // the login attempts for this application. We'll key this by the username and
        // the IP address of the client making these requests into this application.
        if ($this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);

            Log::notice(sprintf(
                '%s from %s locked-out, too many failed login attempts (using webauthn)',
                var_export($request['email'], true),
                $request->ip()
            ));

            return $this->sendLockoutResponse($request);
        }

        if ($this->attemptLogin($request, $verify)) {
            return $this->sendLoginResponse($request);
        }

        // If the login attempt was unsuccessful we will increment the number of attempts
        // to login and redirect the user back to the login form. Of course, when this
        // user surpasses their maximum number of attempts they will get locked out.
        $this->incrementLoginAttempts($request);

        Log::notice(sprintf(
            'Failed login for %s from %s - Attemp %d/%d (using webauthn)',
            var_export($request['email'], true),
            $request->ip(),
            $this->limiter()->attempts($this->throttleKey($request)),
            $this->maxAttempts()
        ));

        return response()->json(['message' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Attempt to log the user into the application.
     *
     * The request user is resolved from the email for the ownership check and
     * for user-scoped allowCredentials, but the session user is ALWAYS the
     * owner of the verified credential (red-team C1): a signed assertion with
     * another account's credential is rejected by the VerifyPasskey
     * ownership check before any login happens.
     *
     * @return bool
     */
    protected function attemptLogin(WebauthnAssertedRequest $request, VerifyPasskey $verify)
    {
        try {
            $user = User::whereRaw('email = ?' . (DB::connection()->getDriverName() === 'sqlite' ? ' COLLATE NOCASE' : ''), [strtolower($request->input('email'))])->first();

            if ($user === null) {
                return false;
            }

            $options = WebAuthn::fromJson(
                OptionsSession::pull($request, OptionsSession::VERIFY_KEY),
                PublicKeyCredentialRequestOptions::class
            );

            $passkey = $verify($request->credential(), $options, $user);

            // Defense in depth: the SSO-only policy is already enforced by the
            // RejectIfSsoOnlyAndNotForAdmin middleware on the route; the passkey
            // layer re-checks it so a middleware bypass cannot log an SSO-only
            // account in with a valid passkey.
            if (! Passkeys::allowsLogin($request, $passkey)) {
                return false;
            }

            $this->passkey = $passkey;

            return true;
        } catch (InvalidPasskeyException | WebauthnException) {
            // InvalidPasskeyException: unrecognized credential or ownership
            // mismatch. WebauthnException is the base of every webauthn-lib
            // ceremony rejection (signature, challenge, origin, rpId,
            // counter) and of its serializer failures on malformed
            // assertions -- all contract-401 outcomes.
            return false;
        } catch (ValidationException) {
            // Expired or missing session options (server-side TTL gate, M2):
            // treated as a failed ceremony, matching the former laragear
            // challenge-expiry behavior (401 unauthorized shape).
            return false;
        }
    }

    /**
     * The passkey verified during attemptLogin.
     *
     * @var Passkey|null
     */
    protected ?Passkey $passkey = null;

    /**
     * Send the response after the user was authenticated.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function sendLoginResponse(WebauthnAssertedRequest $request)
    {
        $this->clearLoginAttempts($request);

        /**
         * Credential-to-owner binding (red-team C1): login the owner of the
         * verified credential, never the email-resolved user.
         */
        $user = $this->passkey->user;

        // Standard guard login: the laragear flow performed it inside the
        // request's login() helper, which no longer exists in the
        // laravel/passkeys flow. The default guard is used, matching the
        // rest of the auth flow.
        Auth::guard()->login($user);

        // Session fixation hardening: the pre-authentication session id must
        // not survive authentication.
        $request->session()->regenerate();

        $this->authenticated($user);

        return response()->json(
            array_merge([
                'message' => 'authenticated',
            ], (new UserResource($user))->resolve($request)),
            Response::HTTP_OK
        );
    }

    /**
     * Get the failed login response instance.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function sendFailedLoginResponse(WebauthnAssertedRequest $request)
    {
        return response()->json(['message' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Redirect the user after determining they are locked out.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function sendLockoutResponse(WebauthnAssertedRequest $request)
    {
        $seconds = $this->limiter()->availableIn(
            $this->throttleKey($request)
        );

        return response()->json(['message' => Lang::get('message.throttle', ['seconds' => $seconds])], Response::HTTP_TOO_MANY_REQUESTS);
    }

    /**
     * Get the login username to be used by the controller.
     *
     * @return string
     */
    public function username()
    {
        return 'email';
    }

    /**
     * The user has been authenticated.
     *
     * @param  mixed  $user
     * @return void
     */
    protected function authenticated($user)
    {
        $user->last_seen_at = Carbon::now()->format('Y-m-d H:i:s');

        if ($user->encryption_enabled && $user->encryption_version > 0) {
            $user->vault_locked = true;
        }

        $user->save();

        Log::info(sprintf('User ID #%s authenticated (using webauthn)', $user->id));
    }
}
