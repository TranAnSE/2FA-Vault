<?php

use App\Http\Controllers\Admin\MetricsController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PersonalAccessTokenController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\Auth\UserController;
use App\Http\Controllers\Auth\WebAuthnDeviceLostController;
use App\Http\Controllers\Auth\WebAuthnLoginController;
use App\Http\Controllers\Auth\WebAuthnManageController;
use App\Http\Controllers\Auth\WebAuthnRecoveryController;
use App\Http\Controllers\Auth\WebAuthnRegisterController;
use App\Http\Controllers\SinglePageController;
use App\Http\Controllers\SystemController;
use App\Http\Middleware\CustomCreateFreshApiToken;
use App\Http\Middleware\MetricsAuthMiddleware;
use App\Http\Middleware\PreventRequestForgery;
use App\Http\Middleware\SetLanguage;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
// use Illuminate\Foundation\Events\DiagnosingHealth;
// use Illuminate\Support\Facades\Event;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

// use App\Models\User;
// use App\Notifications\SignedInWithNewDeviceNotification;
// use App\Models\AuthLog;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

/**
 * Routes that only work for unauthenticated user (otherwise, the user is logged out)
 */
Route::group(['middleware' => ['rejectIfDemoMode', 'RejectIfSsoOnlyAndNotForAdmin', 'forceLogout', 'setLanguage']], function () {
    Route::post('user', [RegisterController::class, 'register'])->name('user.register')->middleware('throttle:5,60');
    Route::post('user/password/lost', [ForgotPasswordController::class, 'sendResetLinkEmail'])->name('user.password.lost')->middleware('throttle:3,60');
    Route::post('user/password/reset', [ResetPasswordController::class, 'reset'])->name('password.reset')->middleware('throttle:3,60');
    // WebAuthn login challenge endpoint — rate-limit to match the login routes
    // (10/min/IP) so the unauthenticated assertion-options endpoint cannot be
    // abused for challenge flooding or email enumeration.
    Route::post('webauthn/login/options', [WebAuthnLoginController::class, 'options'])->name('webauthn.login.options')->middleware('throttle:10,1');
    // Device-lost recovery email — same class of risk as password-lost above
    // (outbound email, user enumeration), so it gets the same 3/min/IP cap.
    Route::post('webauthn/lost', [WebAuthnDeviceLostController::class, 'sendRecoveryEmail'])->name('webauthn.lost')->middleware('throttle:3,60');
});

/**
 * Routes that can be requested max 10 times per minute by the same IP
 */
Route::group(['middleware' => ['rejectIfDemoMode', 'throttle:10,1', 'RejectIfSsoOnlyAndNotForAdmin', 'forceLogout']], function () {
    Route::post('webauthn/recover', [WebAuthnRecoveryController::class, 'recover'])->name('webauthn.recover');
});

/**
 * Routes that only work for unauthenticated user (otherwise, the user is logged out)
 * that can be requested max 10 times per minute by the same IP
 */
Route::group(['middleware' => ['forceLogout', 'throttle:10,1']], function () {
    Route::post('user/login', [LoginController::class, 'login'])->name('user.login')->middleware('RejectIfSsoOnlyAndNotForAdmin');
    Route::post('webauthn/login', [WebAuthnLoginController::class, 'login'])->name('webauthn.login')->middleware('RejectIfSsoOnlyAndNotForAdmin');

    Route::get('/socialite/redirect/{driver}', [SocialiteController::class, 'redirect'])->name('socialite.redirect');
    Route::get('/socialite/callback/{driver}', [SocialiteController::class, 'callback'])->name('socialite.callback');
});

/**
 * Routes protected by an authentication guard but rejected when the reverse-proxy
 * guard is enabled
 */
Route::group(['middleware' => ['behind-auth', 'rejectIfReverseProxy']], function () {
    Route::put('user', [UserController::class, 'update'])->name('user.update')->middleware('throttle:user-mutations');
    Route::patch('user/password', [PasswordController::class, 'update'])->name('user.password.update')->middleware('rejectIfDemoMode', 'throttle:5,1');
    // A10: logout is POST + CSRF-protected. The SPA caller is switched in a
    // later phase; the legacy GET route below answers 410 in the meantime.
    Route::post('user/logout', [LoginController::class, 'logout'])->name('user.logout');
    Route::get('user/logout', function () {
        return response()->json(['message' => 'Logout must be requested using POST.'], 410);
    })->name('user.logout.deprecated');
    Route::delete('user', [UserController::class, 'delete'])->name('user.delete')->middleware('rejectIfDemoMode');

    // Following routes are also forbidden to regular users when "SSO only" is enabled, but using Authorization gates
    Route::get('oauth/personal-access-tokens', [PersonalAccessTokenController::class, 'forUser'])->name('passport.personal.tokens.index')->middleware('throttle:10,1');
    Route::post('oauth/personal-access-tokens', [PersonalAccessTokenController::class, 'store'])->name('passport.personal.tokens.store')->middleware('throttle:10,1');
    Route::delete('oauth/personal-access-tokens/{token_id}', [PersonalAccessTokenController::class, 'destroy'])->name('passport.personal.tokens.destroy')->middleware('throttle:10,1');

    Route::post('webauthn/register/options', [WebAuthnRegisterController::class, 'options'])->name('webauthn.register.options')->middleware('throttle:10,1');
    Route::post('webauthn/register', [WebAuthnRegisterController::class, 'register'])->name('webauthn.register')->middleware('throttle:10,1');
    Route::get('webauthn/credentials', [WebAuthnManageController::class, 'index'])->name('webauthn.credentials.index');
    Route::patch('webauthn/credentials/{credential}/name', [WebAuthnManageController::class, 'rename'])->name('webauthn.credentials.rename');
    Route::delete('webauthn/credentials/{credential}', [WebAuthnManageController::class, 'delete'])->name('webauthn.credentials.delete');
});

/**
 * Routes protected by an authentication guard and restricted to administrators
 */
Route::group(['middleware' => ['behind-auth', 'admin']], function () {
    Route::get('system/infos', [SystemController::class, 'infos'])->name('system.infos');
    Route::post('system/test-email', [SystemController::class, 'testEmail'])->name('system.testEmail');
    Route::get('system/latestRelease', [SystemController::class, 'latestRelease'])->name('system.latestRelease');
    Route::get('system/optimize', [SystemController::class, 'optimize'])->name('system.optimize');
    Route::get('system/clear-cache', [SystemController::class, 'clear'])->name('system.clear');
});

Route::get('refresh-csrf', function (Request $request) {
    $request->session()->regenerateToken();

    return response()->json([
        'token' => csrf_token(),
    ], 200, [
        'Cache-Control' => 'no-store, no-cache, must-revalidate',
        'Pragma'        => 'no-cache',
    ]);
})->middleware('throttle:30,1');

/**
 * Prometheus metrics endpoint
 * Protected by IP allowlist or Bearer token authentication
 */
Route::get('/metrics', [MetricsController::class, 'index'])
    ->name('metrics')
    ->middleware(MetricsAuthMiddleware::class, 'throttle:10,1');

Route::withoutMiddleware([
    StartSession::class,
    PreventRequestForgery::class,
    SubstituteBindings::class,
    SetLanguage::class,
    CustomCreateFreshApiToken::class,
])->get('/up', function () {
    // Event::dispatch(new DiagnosingHealth);
    return view('health', [
        'isSecure' => str_starts_with(config('app.url'), 'https'),
    ]);
});

/**
 * Sentry integration test route.
 * Only registered when explicitly enabled via SENTRY_TEST_ENABLED=true, so it
 * is never exposed in production by default. Hit it once after configuring
 * SENTRY_DSN to confirm events arrive in your Sentry dashboard, then unset
 * SENTRY_TEST_ENABLED again. The thrown exception propagates to the client as
 * a 500 and is captured by the reportable hook in app/Exceptions/Handler.php.
 */
if (filter_var(env('SENTRY_TEST_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
    Route::get('/sentry-test', function () {
        throw new \RuntimeException('Sentry integration test from 2FA-Vault');
    });
}

// Route::get('/notification', function () {
//     $user = User::find(1);
//     return (new SignedInWithNewDeviceNotification(AuthLog::find(9)))
//         ->toMail($user);
// });

/**
 * Route for the main landing view
 */
Route::get('/{any}', [SinglePageController::class, 'index'])->where('any', '.*')->name('landing');

/*
|--------------------------------------------------------------------------
| SPIKE ROUTES (branch spike/passkeys-poc ONLY — removed before merge)
|--------------------------------------------------------------------------
| Scratch endpoints proving laravel/passkeys actions end-to-end against
| converted laragear credentials (plan 261009-passkeys-migration, Phase 1).
*/

use App\Support\Passkeys\CredentialRecordFactory;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Support\WebAuthn;
use Webauthn\PublicKeyCredential;

Route::post('spike/etl', function () {
    $rows = \Laragear\WebAuthn\Models\WebAuthnCredential::all();
    $report = [];

    foreach ($rows as $row) {
        try {
            $record = CredentialRecordFactory::fromLegacyCredential($row);

            $userId = $row->authenticatable_id;

            \DB::table('passkeys')->updateOrInsert(
                ['credential_id' => $row->id],
                [
                    'user_id' => $userId,
                    'name' => $row->alias ?? 'Passkey 1',
                    'credential_id' => $row->id,
                    'credential' => CredentialRecordFactory::toCredentialJson($record),
                    'last_used_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $report[] = ['id' => substr($row->id, 0, 12).'...', 'result' => 'OK', 'handle_hex' => bin2hex($record->userHandle)];
        } catch (Throwable $e) {
            $report[] = ['id' => substr($row->id, 0, 12).'...', 'result' => 'FAIL', 'reason' => $e->getMessage()];
        }
    }

    return response()->json(['report' => $report, 'passkeys' => \DB::table('passkeys')->count()]);
});

Route::post('spike/register-options', function (GenerateRegistrationOptions $generate) {
    $user = auth()->user();
    abort_unless($user, 401);

    $options = $generate($user);
    session()->put('passkey.registration_options', WebAuthn::toJson($options));

    return response()->json(WebAuthn::toBrowserArray($options));
});

Route::post('spike/register', function (StorePasskey $store) {
    $user = auth()->user();
    abort_unless($user, 401);

    $serialized = session()->pull('passkey.registration_options');
    abort_unless($serialized, 422, 'No registration options in session.');

    $options = WebAuthn::fromJson($serialized, \Webauthn\PublicKeyCredentialCreationOptions::class);
    $credential = WebAuthn::fromJson(json_encode(request()->json('credential')), PublicKeyCredential::class);

    $passkey = $store($user, request()->json('name', 'spike-passkey'), $credential, $options);

    return response()->json(['id' => $passkey->id, 'name' => $passkey->name, 'user_id' => $passkey->user_id]);
});

Route::post('spike/verify-options', function (GenerateVerificationOptions $generate) {
    $email = request()->json('email');
    $user = \App\Models\User::where('email', $email)->first();
    abort_unless($user, 404);

    $options = $generate($user);
    session()->put('passkey.verification_options', WebAuthn::toJson($options));

    return response()->json(WebAuthn::toBrowserArray($options));
});

Route::post('spike/verify', function (VerifyPasskey $verify) {
    $serialized = session()->pull('passkey.verification_options');
    abort_unless($serialized, 422, 'No verification options in session.');

    $options = WebAuthn::fromJson($serialized, \Webauthn\PublicKeyCredentialRequestOptions::class);
    $credential = WebAuthn::fromJson(json_encode(request()->json('credential')), PublicKeyCredential::class);

    $expectedUser = request()->json('as_user_id')
        ? \App\Models\User::find(request()->json('as_user_id'))
        : null;

    $passkey = $verify($credential, $options, $expectedUser);

    return response()->json([
        'verified' => true,
        'passkey_id' => $passkey->id,
        'passkey_user_id' => $passkey->user_id,
        'counter_in_record' => $passkey->credential['counter'] ?? null,
    ]);
});

Route::post('spike/user-id', function () {
    $user = \App\Models\User::where('email', request()->json('email'))->first();
    abort_unless($user, 404);

    return response()->json(['user_id' => $user->id]);
});

Route::post('spike/whoami', function () {
    return response()->json([
        'auth_check' => auth()->check(),
        'user_id' => auth()->id(),
        'session_id_len' => strlen(session()->getId()),
    ]);
});

Route::post('spike/clear-cache', function () {
    \Illuminate\Support\Facades\Cache::flush();
    \Illuminate\Support\Facades\RateLimiter::clear('user.register');

    return response()->json(['cleared' => true]);
});

Route::post('spike/clear-passkeys', function () {
    $n = \DB::table('passkeys')->delete();

    return response()->json(['deleted' => $n]);
});
