package com.securitypatrol.app.data.remote.dto

import com.google.gson.annotations.SerializedName

/**
 * DTO — bentuk JSON persis mengikuti response backend (docs/API_SPEC.md).
 */

// ---------------------------------------------------------------- envelope
data class BaseResponse<T>(
    @SerializedName("success") val success: Boolean,
    @SerializedName("message") val message: String? = null,
    @SerializedName("data") val data: T? = null,
    @SerializedName("error_code") val errorCode: String? = null,
)

data class MetaResponse(
    @SerializedName("current_page") val currentPage: Int? = null,
    @SerializedName("per_page") val perPage: Int? = null,
    @SerializedName("total") val total: Int? = null,
    @SerializedName("last_page") val lastPage: Int? = null,
)

// ---------------------------------------------------------------- auth
data class LoginRequest(
    @SerializedName("username") val username: String,
    @SerializedName("password") val password: String,
    @SerializedName("device_uuid") val deviceUuid: String,
    @SerializedName("device_name") val deviceName: String? = null,
    @SerializedName("platform") val platform: String = "android",
    @SerializedName("app_version") val appVersion: String? = null,
)

data class LoginData(
    @SerializedName("token") val token: String,
    @SerializedName("token_type") val tokenType: String? = null,
    @SerializedName("user") val user: UserData? = null,
)

data class UserData(
    @SerializedName("id") val id: Long,
    @SerializedName("name") val name: String? = null,
    @SerializedName("username") val username: String? = null,
    @SerializedName("employee_code") val employeeCode: String? = null,
    @SerializedName("role") val role: String? = null,
)

// ---------------------------------------------------------------- schedule
data class ScheduleRoute(
    @SerializedName("id") val id: Long,
    @SerializedName("name") val name: String? = null,
    @SerializedName("route_type") val routeType: String? = null,
    @SerializedName("area") val area: String? = null,
    @SerializedName("total_checkpoint") val totalCheckpoint: Int? = null,
)

data class TodaySchedule(
    @SerializedName("id") val id: Long,
    @SerializedName("name") val name: String? = null,
    @SerializedName("day_of_week") val dayOfWeek: Int? = null,
    @SerializedName("start_time") val startTime: String? = null,
    @SerializedName("end_time") val endTime: String? = null,
    @SerializedName("grace_before_minutes") val graceBeforeMinutes: Int? = null,
    @SerializedName("grace_after_minutes") val graceAfterMinutes: Int? = null,
    @SerializedName("route") val route: ScheduleRoute? = null,
)

// ---------------------------------------------------------------- patrol
data class StartPatrolRequest(
    @SerializedName("schedule_id") val scheduleId: Long,
    @SerializedName("latitude") val latitude: Double,
    @SerializedName("longitude") val longitude: Double,
    @SerializedName("device_uuid") val deviceUuid: String,
)

data class StartedSession(
    @SerializedName("session_code") val sessionCode: String? = null,
    @SerializedName("status") val status: String? = null,
    @SerializedName("started_at") val startedAt: String? = null,
    @SerializedName("route") val route: ScheduleRoute? = null,
    @SerializedName("total_checkpoint") val totalCheckpoint: Int = 0,
    @SerializedName("completed_checkpoint") val completedCheckpoint: Int = 0,
)

data class SessionInfo(
    @SerializedName("id") val id: Long,
    @SerializedName("session_code") val sessionCode: String? = null,
    @SerializedName("uuid") val uuid: String? = null,
    @SerializedName("status") val status: String? = null,
    @SerializedName("started_at") val startedAt: String? = null,
    @SerializedName("total_checkpoint") val totalCheckpoint: Int = 0,
    @SerializedName("completed_checkpoint") val completedCheckpoint: Int = 0,
)

data class CurrentCheckpoint(
    @SerializedName("id") val id: Long,
    @SerializedName("code") val code: String? = null,
    @SerializedName("name") val name: String? = null,
    @SerializedName("latitude") val latitude: Double = 0.0,
    @SerializedName("longitude") val longitude: Double = 0.0,
    @SerializedName("sequence") val sequence: Int = 0,
    @SerializedName("is_required") val isRequired: Boolean = true,
    @SerializedName("status") val status: String? = null, // COMPLETED / PENDING
)

data class CurrentSchedule(
    @SerializedName("id") val id: Long,
    @SerializedName("name") val name: String? = null,
    @SerializedName("start_time") val startTime: String? = null,
    @SerializedName("end_time") val endTime: String? = null,
)

data class CurrentPatrol(
    @SerializedName("session") val session: SessionInfo? = null,
    @SerializedName("route") val route: ScheduleRoute? = null,
    @SerializedName("schedule") val schedule: CurrentSchedule? = null,
    @SerializedName("checkpoints") val checkpoints: List<CurrentCheckpoint> = emptyList(),
    @SerializedName("progress_percentage") val progressPercentage: Int = 0,
)

data class ScanRequest(
    @SerializedName("session_code") val sessionCode: String,
    @SerializedName("scan_code") val scanCode: String,
    @SerializedName("uuid") val uuid: String,
    @SerializedName("latitude") val latitude: Double,
    @SerializedName("longitude") val longitude: Double,
    @SerializedName("gps_accuracy") val gpsAccuracy: Double? = null,
    @SerializedName("device_timestamp") val deviceTimestamp: String? = null,
    @SerializedName("device_uuid") val deviceUuid: String? = null,
)

data class ScanProgress(
    @SerializedName("completed") val completed: Int = 0,
    @SerializedName("total") val total: Int = 0,
    @SerializedName("percentage") val percentage: Int = 0,
)

data class ScanResult(
    @SerializedName("checkpoint") val checkpoint: NameCode? = null,
    @SerializedName("scanned_at") val scannedAt: String? = null,
    @SerializedName("distance_meter") val distanceMeter: Double? = null,
    @SerializedName("validation_status") val validationStatus: String? = null,
    @SerializedName("progress") val progress: ScanProgress? = null,
)

data class NameCode(
    @SerializedName("code") val code: String? = null,
    @SerializedName("name") val name: String? = null,
)

data class CompleteRequest(
    @SerializedName("session_code") val sessionCode: String,
    @SerializedName("latitude") val latitude: Double,
    @SerializedName("longitude") val longitude: Double,
)

data class CompleteResult(
    @SerializedName("status") val status: String? = null,
    @SerializedName("started_at") val startedAt: String? = null,
    @SerializedName("completed_at") val completedAt: String? = null,
    @SerializedName("duration_seconds") val durationSeconds: Long? = null,
    @SerializedName("checkpoint_completed") val checkpointCompleted: Int = 0,
    @SerializedName("checkpoint_total") val checkpointTotal: Int = 0,
)

data class CancelRequest(
    @SerializedName("session_code") val sessionCode: String,
    @SerializedName("reason") val reason: String? = null,
)

// ---------------------------------------------------------------- history
data class HistoryItem(
    @SerializedName("session_code") val sessionCode: String? = null,
    @SerializedName("status") val status: String? = null,
    @SerializedName("started_at") val startedAt: String? = null,
    @SerializedName("completed_at") val completedAt: String? = null,
    @SerializedName("duration_seconds") val durationSeconds: Long? = null,
    @SerializedName("route") val route: String? = null,
    @SerializedName("total_checkpoint") val totalCheckpoint: Int = 0,
    @SerializedName("completed_checkpoint") val completedCheckpoint: Int = 0,
)

data class CheckinItem(
    @SerializedName("checkpoint") val checkpoint: NameCode? = null,
    @SerializedName("scanned_at") val scannedAt: String? = null,
    @SerializedName("device_timestamp") val deviceTimestamp: String? = null,
    @SerializedName("latitude") val latitude: Double? = null,
    @SerializedName("longitude") val longitude: Double? = null,
    @SerializedName("distance_meter") val distanceMeter: Double? = null,
    @SerializedName("validation_status") val validationStatus: String? = null,
)

data class PatrolDetail(
    @SerializedName("session_code") val sessionCode: String? = null,
    @SerializedName("status") val status: String? = null,
    @SerializedName("started_at") val startedAt: String? = null,
    @SerializedName("completed_at") val completedAt: String? = null,
    @SerializedName("duration_seconds") val durationSeconds: Long? = null,
    @SerializedName("officer") val officer: NameCode? = null,
    @SerializedName("route") val route: ScheduleRoute? = null,
    @SerializedName("schedule") val schedule: String? = null,
    @SerializedName("total_checkpoint") val totalCheckpoint: Int = 0,
    @SerializedName("completed_checkpoint") val completedCheckpoint: Int = 0,
    @SerializedName("checkins") val checkins: List<CheckinItem> = emptyList(),
)

// ---------------------------------------------------------------- offline sync
data class SyncItem(
    @SerializedName("uuid") val uuid: String,
    @SerializedName("session_code") val sessionCode: String,
    @SerializedName("checkpoint_code") val checkpointCode: String,
    @SerializedName("latitude") val latitude: Double,
    @SerializedName("longitude") val longitude: Double,
    @SerializedName("gps_accuracy") val gpsAccuracy: Double? = null,
    @SerializedName("device_timestamp") val deviceTimestamp: String? = null,
    @SerializedName("device_uuid") val deviceUuid: String? = null,
)

data class SyncSummary(
    @SerializedName("processed") val processed: Int = 0,
    @SerializedName("success") val success: Int = 0,
    @SerializedName("duplicate") val duplicate: Int = 0,
    @SerializedName("failed") val failed: Int = 0,
)

data class SyncItemResult(
    @SerializedName("uuid") val uuid: String? = null,
    @SerializedName("session_code") val sessionCode: String? = null,
    @SerializedName("checkpoint_code") val checkpointCode: String? = null,
    @SerializedName("status") val status: String? = null,
    @SerializedName("error_code") val errorCode: String? = null,
    @SerializedName("message") val message: String? = null,
)

data class SyncResult(
    @SerializedName("summary") val summary: SyncSummary? = null,
    @SerializedName("items") val items: List<SyncItemResult> = emptyList(),
)
