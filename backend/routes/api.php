<?php

use App\Http\Controllers\Api\Admin\AreaController;
use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\CheckpointController;
use App\Http\Controllers\Api\Admin\DeviceController;
use App\Http\Controllers\Api\Admin\RouteController;
use App\Http\Controllers\Api\Admin\ScheduleController;
use App\Http\Controllers\Api\Admin\SessionController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PatrolController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 Routes  (prefix: /api/v1)
|--------------------------------------------------------------------------
| Public:   auth
| Security (Android):  patrol/*, sync  — must be role security
| Supervisor:          dashboard/read, reports, sessions (read)
| Super admin:         full admin CRUD
*/

// ------------------------------------------------------------- public auth
Route::post('/auth/login', [AuthController::class, 'login']);

// ---------------------------------------------------- authenticated (all)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
});

// ------------------------------------------------- security app (Android)
Route::middleware(['auth:sanctum', 'role:security'])->prefix('patrol')->group(function () {
    Route::get('/schedules/today', [PatrolController::class, 'todaySchedules']);
    Route::post('/start', [PatrolController::class, 'start']);
    Route::get('/current', [PatrolController::class, 'current']);
    Route::post('/checkpoint/scan', [PatrolController::class, 'scan']);
    Route::post('/complete', [PatrolController::class, 'complete']);
    Route::post('/cancel', [PatrolController::class, 'cancel']);
    Route::get('/history', [PatrolController::class, 'history']);
    Route::get('/detail/{sessionCode}', [PatrolController::class, 'detail']);
});

// ------------------------------------------------------- offline sync app
Route::middleware(['auth:sanctum', 'role:security'])->post('/sync', [SyncController::class, 'sync']);

// ------------------------------------------- dashboard + reports (web)
Route::middleware(['auth:sanctum', 'role:super_admin,supervisor'])->group(function () {
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/active-patrols', [DashboardController::class, 'activePatrols']);
    Route::get('/dashboard/officer-positions', [DashboardController::class, 'officerPositions']);

    Route::prefix('reports')->group(function () {
        Route::get('/daily', [ReportController::class, 'daily']);
        Route::get('/monthly', [ReportController::class, 'monthly']);
        Route::get('/attendance', [ReportController::class, 'attendance']);
        Route::get('/export/daily', [ReportController::class, 'exportDaily']);
        Route::get('/export/range', [ReportController::class, 'exportRange']);
    });
});

// --------------------------------------- session supervision (web)
Route::middleware(['auth:sanctum', 'role:super_admin,supervisor'])->group(function () {
    Route::get('/sessions', [SessionController::class, 'index']);
    Route::get('/sessions/{id}', [SessionController::class, 'show']);
    Route::post('/sessions/{id}/incomplete', [SessionController::class, 'markIncomplete']);
});

// ------------------------------------------------------- admin CRUD
Route::middleware(['auth:sanctum', 'role:super_admin'])->prefix('admin')->group(function () {
    // users
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
    Route::get('/roles', [UserController::class, 'roles']);

    // areas
    Route::get('/areas', [AreaController::class, 'index']);
    Route::post('/areas', [AreaController::class, 'store']);
    Route::get('/areas/{id}', [AreaController::class, 'show']);
    Route::put('/areas/{id}', [AreaController::class, 'update']);
    Route::delete('/areas/{id}', [AreaController::class, 'destroy']);

    // checkpoints
    Route::get('/checkpoints', [CheckpointController::class, 'index']);
    Route::post('/checkpoints', [CheckpointController::class, 'store']);
    Route::get('/checkpoints/{id}', [CheckpointController::class, 'show']);
    Route::get('/checkpoints/{id}/qr', [CheckpointController::class, 'qr']);
    Route::put('/checkpoints/{id}', [CheckpointController::class, 'update']);
    Route::delete('/checkpoints/{id}', [CheckpointController::class, 'destroy']);

    // routes
    Route::get('/routes', [RouteController::class, 'index']);
    Route::post('/routes', [RouteController::class, 'store']);
    Route::get('/routes/{id}', [RouteController::class, 'show']);
    Route::put('/routes/{id}', [RouteController::class, 'update']);
    Route::delete('/routes/{id}', [RouteController::class, 'destroy']);

    // schedules
    Route::get('/schedules', [ScheduleController::class, 'index']);
    Route::post('/schedules', [ScheduleController::class, 'store']);
    Route::get('/schedules/{id}', [ScheduleController::class, 'show']);
    Route::put('/schedules/{id}', [ScheduleController::class, 'update']);
    Route::delete('/schedules/{id}', [ScheduleController::class, 'destroy']);

    // devices
    Route::get('/devices', [DeviceController::class, 'index']);
    Route::post('/devices/{id}/block', [DeviceController::class, 'setStatus'])->defaults('action', 'block');
    Route::post('/devices/{id}/unblock', [DeviceController::class, 'setStatus'])->defaults('action', 'unblock');

    // audit
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
    Route::get('/audit-logs/actions', [AuditLogController::class, 'actions']);
});
