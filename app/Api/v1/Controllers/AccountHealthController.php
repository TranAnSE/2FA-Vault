<?php

namespace App\Api\v1\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TwoFAccount;
use App\Services\AccountHealthService;
use Illuminate\Http\JsonResponse;

/**
 * Exposes per-account and vault-wide security health scores computed from
 * server-visible metadata only (safe under E2EE).
 */
class AccountHealthController extends Controller
{
    public function __construct(private readonly AccountHealthService $health) {}

    /**
     * Health score for a single account.
     */
    public function show(TwoFAccount $twofaccount) : JsonResponse
    {
        $this->authorize('view', $twofaccount);

        return response()->json(
            array_merge(['id' => (int) $twofaccount->id], $this->health->computeServerScore($twofaccount))
        );
    }

    /**
     * Aggregated health summary for all of the authenticated user's accounts.
     */
    public function summary() : JsonResponse
    {
        // Single query selecting only the columns the service reads (avoid N+1 / heavy payloads)
        $accounts = $this->userAccounts()->get();

        $summary = $this->health->summarize($accounts);

        return response()->json($summary);
    }

    /**
     * Auth-scoped query over the columns the scoring service inspects.
     */
    private function userAccounts()
    {
        return auth()->user()
            ->twofaccounts()
            ->select(['id', 'otp_type', 'algorithm', 'digits', 'period', 'last_used_at']);
    }
}
