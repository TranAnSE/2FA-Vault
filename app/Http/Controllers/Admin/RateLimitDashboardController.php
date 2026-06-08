<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\RateLimitMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RateLimitDashboardController extends Controller
{
    public function __construct(protected RateLimitMonitorService $service) {}

    public function index(Request $request): JsonResponse
    {
        $period = $request->get('period', '24h');
        if (!in_array($period, ['24h', '7d', '30d'])) {
            $period = '24h';
        }

        return response()->json($this->service->getDashboardData($period));
    }
}
