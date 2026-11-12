<?php

namespace App\Http\Controllers\Auth;

use App\Extensions\WebauthnCredentialBroker;
use App\Http\Controllers\Controller;
use App\Http\Requests\WebauthnRecoveryRequest;
use App\Models\User;
use App\Models\UserSession;
use App\Services\CredentialRevocationService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Foundation\Auth\ResetsPasswords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class WebAuthnRecoveryController extends Controller
{
    use ResetsPasswords;

    /**
     * Let the user regain access to his account using email+password by resetting
     * the "use webauthn only" setting.
     *
     * @return RedirectResponse|JsonResponse
     *
     * @throws ValidationException
     */
    public function recover(WebauthnRecoveryRequest $request, WebauthnCredentialBroker $broker)
    {
        $credentials = $request->validated();

        $response = $broker->reset(
            $credentials,
            function ($user) use ($request) {
                // At this time, the TwoFAuthUserProvider is already registered in the Laravel Service Container,
                // and rejects password validation for useWebauthnOnly users (see AuthServiceProvider.php and
                // TwoFAuthUserProvider.php). To ensure user login with email+pwd credentials during recovery,
                // we replace the registered provider with a stock EloquentUserProvider, which performs plain
                // password validation (plan 261009-passkeys-migration, red-team H5).
                Auth::setProvider(new EloquentUserProvider(app()->make('hash'), User::class));

                if (Auth::attempt($request->only('email', 'password'))) {
                    if ($this->shouldRevokeAllCredentials($request)) {
                        $user->flushCredentials();
                    }
                    $user['preferences->useWebauthnOnly'] = false;
                    $user->save();
                    Log::notice(sprintf('Legacy login restored for user ID #%s', $user->id));

                    // The recovery flow resets the password, so every
                    // credential obtained with the old one is revoked.
                    app(CredentialRevocationService::class)->revokeAllFor($user->refresh());
                } else {
                    throw new AuthenticationException;
                }
            }
        );

        return $response === Password::PASSWORD_RESET
            ? $this->sendRecoveryResponse($request, $response)
            : $this->sendRecoveryFailedResponse($request, $response);
    }

    /**
     * Check if the user has set to revoke all credentials.
     *
     * @return bool|mixed
     */
    protected function shouldRevokeAllCredentials(WebauthnRecoveryRequest $request) : mixed
    {
        return filter_var($request->header('WebAuthn-Unique'), FILTER_VALIDATE_BOOLEAN)
            ?: $request->input('revokeAll', true);
    }

    /**
     * Get the response for a successful account recovery.
     */
    protected function sendRecoveryResponse(Request $request, string $response) : JsonResponse
    {
        $user = $request->user();

        // Keep the session-management bookkeeping consistent with a regular
        // login: the recovered user is now authenticated with a fresh session
        // and the vault is re-armed (A9).
        if ($user) {
            if ($user->encryption_enabled && $user->encryption_version > 0) {
                $user->vault_locked = true;
                $user->save();
            }

            if ($request->hasSession()) {
                $request->session()->regenerate();

                UserSession::updateOrCreate(
                    ['token_id' => $request->session()->getId()],
                    [
                        'user_id'        => $user->id,
                        'ip_address'     => $request->ip(),
                        'user_agent'     => $request->userAgent(),
                        'last_active_at' => now(),
                    ]
                );
            }
        }

        return response()->json(['message' => __('message.webauthn_login_disabled')]);
    }

    /**
     * Get the response for a failed account recovery.
     *
     *
     * @throws ValidationException
     */
    protected function sendRecoveryFailedResponse(Request $request, string $response) : JsonResponse
    {
        switch ($response) {
            case Password::INVALID_TOKEN:
                throw ValidationException::withMessages(['token' => [__('message.invalid_reset_token')]]);
            default:
                throw ValidationException::withMessages(['email' => [trans($response)]]);
        }
    }
}
