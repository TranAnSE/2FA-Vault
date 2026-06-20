<?php

namespace App\Api\v1\Controllers;

use App\Api\v1\Requests\BreachCheckEmailRequest;
use App\Http\Controllers\Controller;
use App\Services\BreachMonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Breach monitoring via HaveIBeenPwned.
 *
 * Email checks send the address to a third party and are HARD-gated behind the
 * user's `breachMonitoring` preference (off by default). Service/domain checks
 * use public breach data and require no opt-in.
 */
class BreachController extends Controller
{
    public function __construct(private readonly BreachMonitoringService $breaches) {}

    /**
     * Check an email address against HIBP. Gated by the breachMonitoring opt-in.
     */
    public function checkEmail(BreachCheckEmailRequest $request) : JsonResponse
    {
        $user = $request->user();

        // Hard privacy gate: never send the email to HIBP without explicit opt-in.
        if (! (bool) ($user->preferences['breachMonitoring'] ?? false)) {
            return response()->json([
                'message' => 'Breach monitoring is disabled. Enable it in settings to check email addresses.',
            ], 403);
        }

        $email = (bool) $request->input('use_account_email')
            ? $user->email
            : $request->input('email');

        return response()->json(
            array_merge(['email' => $email], $this->breaches->checkEmail($email))
        );
    }

    /**
     * Check a service/domain against the public HIBP breaches list. No opt-in.
     */
    public function checkService(Request $request) : JsonResponse
    {
        $service = trim((string) $request->query('service', ''));

        if ($service === '') {
            return response()->json([
                'message' => 'The service parameter is required.',
            ], 422);
        }

        return response()->json(
            array_merge(['service' => $service], $this->breaches->checkService($service))
        );
    }
}
