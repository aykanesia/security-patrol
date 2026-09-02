package com.securitypatrol.app.data

import com.securitypatrol.app.data.local.SessionManager
import com.securitypatrol.app.data.remote.ApiClient
import com.securitypatrol.app.data.remote.ApiException
import com.securitypatrol.app.data.remote.PatrolApi
import com.securitypatrol.app.data.remote.dto.*
import com.securitypatrol.app.util.TimeFormat
import retrofit2.HttpException
import retrofit2.Response
import java.io.IOException

/**
 * Repository — satu-satunya pintu akses jaringan dari UI.
 * Menerjemahkan retrofit Response + envelope backend menjadi hasil
 * yang aman: throw ApiException(message, errorCode) bila gagal.
 */
class PatrolRepository(private val session: SessionManager) {

    private val api: PatrolApi get() = ApiClient.api

    // ----------------------------------------------------------------- auth
    suspend fun login(username: String, password: String): LoginData {
        val res = api.login(
            LoginRequest(
                username = username,
                password = password,
                deviceUuid = session.deviceUuid,
                deviceName = android.os.Build.MODEL,
                platform = "android",
                appVersion = "1.0.0",
            ),
        )
        val body = unwrap(res)
        val data = body.data ?: throw ApiException("EMPTY", res.code(), "Response kosong")
        return data
    }

    suspend fun logout() {
        runCatching { unwrap(api.logout()) }
    }

    // ------------------------------------------------------------- schedule
    suspend fun todaySchedules(): List<TodaySchedule> {
        val body = unwrap(api.todaySchedules())
        return body.data ?: emptyList()
    }

    // -------------------------------------------------------------- patrol
    suspend fun startPatrol(scheduleId: Long, lat: Double, lon: Double): StartedSession {
        val body = unwrap(
            api.startPatrol(
                StartPatrolRequest(scheduleId, lat, lon, session.deviceUuid),
            ),
        )
        return body.data ?: throw ApiException("EMPTY", 200, "Response kosong")
    }

    suspend fun currentPatrol(): CurrentPatrol? {
        val body = unwrap(api.currentPatrol())
        return body.data
    }

    suspend fun scan(
        sessionCode: String,
        scanCode: String,
        lat: Double,
        lon: Double,
        accuracy: Double,
    ): ScanResult {
        val body = unwrap(
            api.scanCheckpoint(
                ScanRequest(
                    sessionCode = sessionCode,
                    scanCode = scanCode,
                    uuid = java.util.UUID.randomUUID().toString(),
                    latitude = lat,
                    longitude = lon,
                    gpsAccuracy = accuracy,
                    deviceTimestamp = TimeFormat.nowServer(),
                    deviceUuid = session.deviceUuid,
                ),
            ),
        )
        return body.data ?: ScanResult(validationStatus = "VALID")
    }

    suspend fun complete(sessionCode: String, lat: Double, lon: Double): CompleteResult {
        val body = unwrap(api.completePatrol(CompleteRequest(sessionCode, lat, lon)))
        return body.data ?: throw ApiException("EMPTY", 200, "Response kosong")
    }

    suspend fun cancel(sessionCode: String) {
        unwrap(api.cancelPatrol(CancelRequest(sessionCode, "dibatalkan dari aplikasi")))
    }

    suspend fun history(): List<HistoryItem> {
        val body = unwrap(api.patrolHistory())
        return body.data ?: emptyList()
    }

    suspend fun detail(sessionCode: String): PatrolDetail {
        val body = unwrap(api.patrolDetail(sessionCode))
        return body.data ?: throw ApiException("EMPTY", 200, "Response kosong")
    }

    // ---------------------------------------------------------- offline sync
    suspend fun syncBatch(items: List<SyncItem>): SyncResult {
        val body = unwrap(api.sync(SyncRequest(items)))
        return body.data ?: SyncResult()
    }

    /** Deteksi error jaringan (offline) vs error bisnis. */
    fun isNetworkError(t: Throwable): Boolean =
        t is IOException || t is HttpException && t.code() >= 500

    // ----------------------------------------------------------------- util
    private fun <T> unwrap(res: Response<BaseResponse<T>>): BaseResponse<T> {
        val body = res.body()
        if (res.isSuccessful && body != null && body.success) {
            return body
        }
        // error envelope backend
        if (body != null && body.errorCode != null) {
            throw ApiException(body.errorCode, res.code(), body.message)
        }
        // body tidak terbaca (mis. 422 validation dari Laravel)
        if (!res.isSuccessful) {
            throw ApiException("HTTP_${res.code()}", res.code(), "Terjadi kesalahan server (${res.code()})")
        }
        throw ApiException("UNKNOWN", res.code(), "Response tidak dikenal")
    }
}
