<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\DashboardService;
use Illuminate\Http\Request;

/**
 * Web dashboard data (super_admin & supervisor).
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function stats(Request $request)
    {
        $date = $request->has('date') ? \Carbon\Carbon::parse($request->date) : now();

        return ApiResponse::success($this->dashboard->stats($date));
    }

    public function activePatrols()
    {
        return ApiResponse::success($this->dashboard->activePatrols());
    }

    public function officerPositions()
    {
        return ApiResponse::success($this->dashboard->officerPositions());
    }
}
