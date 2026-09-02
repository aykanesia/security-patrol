<?php

namespace App\Console\Commands;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Notification;
use App\Models\PatrolSchedule;
use App\Models\PatrolSession;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Cek jadwal yang sudah lewat grace period tapi belum ada session patroli.
 * Kirim notifikasi ke seluruh supervisor aktif (tipe PATROL_NOT_STARTED).
 * Dijalankan via scheduler (routes/console.php) — aman di-idempotensi:
 * tidak akan membuat notifikasi ganda untuk (jadwal, petugas) yang sama per hari.
 */
class NotifyMissedPatrol extends Command
{
    protected $signature = 'patrol:notify-missed {--dry : tampilkan saja tanpa menyimpan}';

    protected $description = 'Kirim notifikasi ke supervisor jika patroli terjadwal belum dimulai';

    public function handle(): int
    {
        $now = now();
        $todayDow = (int) $now->format('w');
        $dry = (bool) $this->option('dry');
        $created = 0;
        $skipped = 0;

        $schedules = PatrolSchedule::with(['assignments.user'])
            ->where('status', 'ACTIVE')
            ->get()
            ->filter(fn (PatrolSchedule $s) => $s->isActiveOnDay($todayDow));

        foreach ($schedules as $schedule) {
            $start = Carbon::parse($schedule->start_time);
            $threshold = $start->copy()->addMinutes((int) $schedule->grace_after_minutes);

            // belum melewati ambang "terlambat mulai" → lewati
            if ($now->lessThan($threshold)) {
                $skipped++;
                continue;
            }

            $dayStart = $now->copy()->startOfDay();

            foreach ($schedule->assignments as $assignment) {
                $user = $assignment->user;
                if (! $user || $user->status !== UserStatus::ACTIVE->value) {
                    continue;
                }

                $hasSession = PatrolSession::where('schedule_id', $schedule->id)
                    ->where('user_id', $user->id)
                    ->where('started_at', '>=', $dayStart)
                    ->exists();

                if ($hasSession) {
                    continue;
                }

                $already = Notification::where('type', 'PATROL_NOT_STARTED')
                    ->whereDate('created_at', $now->toDateString())
                    ->where('data->schedule_id', $schedule->id)
                    ->where('data->user_id', $user->id)
                    ->exists();

                if ($already) {
                    $skipped++;
                    continue;
                }

                $this->line(sprintf(
                    '  [%s] jadwal "%s" (%s) belum dimulai oleh %s',
                    $schedule->id,
                    $schedule->name,
                    $schedule->start_time,
                    $user->name,
                ));

                if (! $dry) {
                    $supervisors = User::where('status', UserStatus::ACTIVE->value)
                        ->whereHas('role', fn ($q) => $q->where('name', RoleName::SUPERVISOR->value))
                        ->get();

                    foreach ($supervisors as $supervisor) {
                        Notification::create([
                            'user_id' => $supervisor->id,
                            'type' => 'PATROL_NOT_STARTED',
                            'title' => 'Patroli belum dimulai',
                            'message' => sprintf(
                                '%s belum memulai patroli "%s" (jadwal %s - %s).',
                                $user->name,
                                $schedule->name,
                                $schedule->start_time,
                                $schedule->end_time,
                            ),
                            'data' => [
                                'schedule_id' => $schedule->id,
                                'user_id' => $user->id,
                                'route_id' => $schedule->route_id,
                            ],
                        ]);
                        $created++;
                    }
                }
            }
        }

        $this->info("Selesai. {$created} notifikasi dibuat, {$skipped} dilewati.");
        $this->newLine();

        return self::SUCCESS;
    }
}
