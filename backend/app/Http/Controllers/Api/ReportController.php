<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports (web, super_admin & supervisor).
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $report) {}

    public function daily(Request $request)
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
        ]);

        $date = isset($validated['date']) ? \Carbon\Carbon::parse($validated['date']) : now();

        return ApiResponse::success($this->report->dailyReport($date));
    }

    public function monthly(Request $request)
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ]);

        $year = $validated['year'] ?? now()->year;
        $month = $validated['month'] ?? now()->month;

        return ApiResponse::success($this->report->monthlyReport($year, $month));
    }

    public function attendance(Request $request)
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        return ApiResponse::success($this->report->attendanceReport(
            \Carbon\Carbon::parse($validated['from']),
            \Carbon\Carbon::parse($validated['to']),
        ));
    }

    public function exportDaily(Request $request)
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'format' => ['nullable', 'in:csv'],
        ]);

        $date = isset($validated['date']) ? \Carbon\Carbon::parse($validated['date']) : now();
        $csv = $this->report->dailyToCsv($date);

        return $this->csvResponse('patrol-report-' . $date->toDateString() . '.csv', $csv);
    }

    public function exportRange(Request $request)
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'format' => ['nullable', 'in:csv'],
        ]);

        $csv = $this->report->rangeToCsv(
            \Carbon\Carbon::parse($validated['from']),
            \Carbon\Carbon::parse($validated['to']),
        );

        return $this->csvResponse('patrol-report-' . $validated['from'] . '_' . $validated['to'] . '.csv', $csv);
    }

    private function csvResponse(string $filename, string $csv): StreamedResponse
    {
        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
