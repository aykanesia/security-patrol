<?php

namespace App\Services;

use App\Enums\PatrolSessionStatus;
use App\Models\PatrolCheckin;
use App\Models\PatrolSchedule;
use App\Models\PatrolSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Reporting: daily, monthly, per-officer attendance & compliance.
 */
class ReportService
{
    // ================================================================ DAILY

    public function dailyReport(Carbon $date): array
    {
        $sessions = PatrolSession::whereDate('started_at', $date->toDateString())->get();

        $checkins = PatrolCheckin::whereDate('scanned_at', $date->toDateString())->get();

        return [
            'date' => $date->toDateString(),
            'total_patrol' => $sessions->count(),
            'completed' => $sessions->where('status', PatrolSessionStatus::COMPLETED->value)->count(),
            'incomplete' => $sessions->where('status', PatrolSessionStatus::INCOMPLETE->value)->count(),
            'cancelled' => $sessions->where('status', PatrolSessionStatus::CANCELLED->value)->count(),
            'running' => $sessions->where('status', PatrolSessionStatus::RUNNING->value)->count(),
            'total_checkin' => $checkins->count(),
            'valid_checkin' => $checkins->where('validation_status', 'VALID')->count(),
            'failed_checkin' => $checkins->where('validation_status', '!=', 'VALID')->count(),
            'active_officers' => $sessions->pluck('user_id')->unique()->count(),
            'total_expected_officers' => $this->expectedOfficersOn($date),
        ];
    }

    // ============================================================== MONTHLY

    public function monthlyReport(int $year, int $month): array
    {
        $from = Carbon::create($year, $month, 1)->startOfDay();
        $to = $from->copy()->endOfMonth()->endOfDay();

        $sessions = PatrolSession::whereBetween('started_at', [$from, $to])->get();

        $perOfficer = $sessions
            ->groupBy('user_id')
            ->map(function (Collection $rows) {
                $total = $rows->count();
                $completed = $rows->where('status', PatrolSessionStatus::COMPLETED->value)->count();
                $incomplete = $rows->where('status', PatrolSessionStatus::INCOMPLETE->value)->count();
                $cancelled = $rows->where('status', PatrolSessionStatus::CANCELLED->value)->count();
                $durations = $rows
                    ->filter(fn ($s) => $s->started_at && $s->completed_at)
                    ->map(fn ($s) => $s->started_at->diffInSeconds($s->completed_at));

                $user = User::find($rows->first()->user_id);

                return [
                    'officer_id' => $rows->first()->user_id,
                    'officer_name' => $user?->name ?? 'Unknown',
                    'employee_code' => $user?->employee_code,
                    'total_patrol' => $total,
                    'completed' => $completed,
                    'incomplete' => $incomplete,
                    'cancelled' => $cancelled,
                    'compliance_percentage' => $total > 0 ? round($completed / $total * 100, 1) : 0,
                    'avg_duration_seconds' => $durations->isNotEmpty()
                        ? (int) round($durations->avg())
                        : 0,
                    'total_duration_seconds' => (int) $durations->sum(),
                ];
            })
            ->values();

        return [
            'year' => $year,
            'month' => $month,
            'total_patrol' => $sessions->count(),
            'completed' => $sessions->where('status', PatrolSessionStatus::COMPLETED->value)->count(),
            'incomplete' => $sessions->where('status', PatrolSessionStatus::INCOMPLETE->value)->count(),
            'cancelled' => $sessions->where('status', PatrolSessionStatus::CANCELLED->value)->count(),
            'per_officer' => $perOfficer,
        ];
    }

    // ======================================================== ATTENDANCE

    /**
     * Patrol attendance/compliance per officer within a date range.
     */
    public function attendanceReport(Carbon $from, Carbon $to): array
    {
        $officers = User::whereHas('role', fn ($q) => $q->where('name', 'security'))
            ->where('status', 'ACTIVE')
            ->get();

        $sessions = PatrolSession::whereBetween('started_at', [$from->startOfDay(), $to->endOfDay()])->get();

        $rows = $officers->map(function (User $officer) use ($sessions, $from, $to) {
            $mine = $sessions->where('user_id', $officer->id);

            $scheduled = PatrolSchedule::whereHas('assignments', fn ($q) => $q->where('user_id', $officer->id))
                ->where('status', 'ACTIVE')
                ->get()
                ->filter(fn ($schedule) => $this->scheduleOccursBetween($schedule, $from, $to))
                ->count();

            $completed = $mine->where('status', PatrolSessionStatus::COMPLETED->value)->count();
            $incomplete = $mine->where('status', PatrolSessionStatus::INCOMPLETE->value)->count();

            return [
                'officer_id' => $officer->id,
                'officer_name' => $officer->name,
                'employee_code' => $officer->employee_code,
                'phone' => $officer->phone,
                'total_scheduled' => $scheduled,
                'total_patrol' => $mine->count(),
                'completed' => $completed,
                'incomplete' => $incomplete,
                'missed' => max(0, $scheduled - $mine->count()),
                'compliance_percentage' => $scheduled > 0 ? round($mine->count() / $scheduled * 100, 1) : 0,
            ];
        });

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'officers' => $rows,
        ];
    }

    // ======================================================== EXPORT HELPERS

    public function dailyToCsv(Carbon $date): string
    {
        $rows = $this->sessionRowsForExport($date->copy()->startOfDay(), $date->copy()->endOfDay());

        return $this->rowsToCsv($rows);
    }

    public function rangeToCsv(Carbon $from, Carbon $to): string
    {
        $rows = $this->sessionRowsForExport($from->copy()->startOfDay(), $to->copy()->endOfDay());

        return $this->rowsToCsv($rows);
    }

    // ====================================================== INTERNAL

    private function sessionRowsForExport(Carbon $from, Carbon $to): array
    {
        $sessions = PatrolSession::with(['user', 'route', 'schedule'])
            ->whereBetween('started_at', [$from, $to])
            ->orderBy('started_at')
            ->get();

        return $sessions->map(fn (PatrolSession $s) => [
            'Tanggal' => $s->started_at?->format('Y-m-d'),
            'Jam Mulai' => $s->started_at?->format('H:i:s'),
            'Jam Selesai' => $s->completed_at?->format('H:i:s'),
            'Durasi (detik)' => $s->durationSeconds() ?? '',
            'Session' => $s->session_code,
            'Petugas' => $s->user?->name,
            'NIK' => $s->user?->employee_code,
            'Rute' => $s->route?->name,
            'Jadwal' => $s->schedule?->name,
            'Checkpoint Selesai' => $s->completed_checkpoint,
            'Total Checkpoint' => $s->total_checkpoint,
            'Status' => $s->status,
        ])->values()->all();
    }

    private function rowsToCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if (! empty($rows)) {
            fputcsv($handle, array_keys($rows[0]));
        }

        foreach ($rows as $row) {
            fputcsv($handle, array_values($row));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function expectedOfficersOn(Carbon $date): int
    {
        $day = (int) $date->format('w');

        return PatrolSchedule::where('status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('day_of_week')->orWhere('day_of_week', $day))
            ->get()
            ->flatMap(fn ($s) => $s->assignments()->pluck('user_id'))
            ->unique()
            ->count();
    }

    private function scheduleOccursBetween(PatrolSchedule $schedule, Carbon $from, Carbon $to): bool
    {
        $fromDay = (int) $from->format('w');
        $toDay = (int) $to->format('w');

        if ($schedule->day_of_week === null) {
            return true; // daily schedule
        }

        // iterate days in range, checking day_of_week match
        $cursor = $from->copy()->startOfDay();
        while ($cursor->lte($to->copy()->endOfDay())) {
            if ((int) $cursor->format('w') === $schedule->day_of_week) {
                return true;
            }
            $cursor->addDay();
        }

        return false;
    }
}
