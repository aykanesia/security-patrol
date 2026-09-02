package com.securitypatrol.app.data.remote

import com.securitypatrol.app.data.remote.dto.BaseResponse
import com.securitypatrol.app.data.remote.dto.*
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * REST API — base URL dari BuildConfig.API_BASE_URL.
 * Semua method mengembalikan retrofit2.Response agar repository bisa
 * membaca HTTP status sekaligus body error (envelope sukses:false).
 */
interface PatrolApi {

    // ----------------------------------------------------------------- auth
    @POST("auth/login")
    suspend fun login(@Body body: LoginRequest): Response<BaseResponse<LoginData>>

    @POST("auth/logout")
    suspend fun logout(): Response<BaseResponse<com.google.gson.JsonObject>>

    // ------------------------------------------------------------- schedule
    @GET("patrol/schedules/today")
    suspend fun todaySchedules(): Response<BaseResponse<List<TodaySchedule>>>

    // -------------------------------------------------------------- patrol
    @POST("patrol/start")
    suspend fun startPatrol(@Body body: StartPatrolRequest): Response<BaseResponse<StartedSession>>

    @GET("patrol/current")
    suspend fun currentPatrol(): Response<BaseResponse<CurrentPatrol>>

    @POST("patrol/checkpoint/scan")
    suspend fun scanCheckpoint(@Body body: ScanRequest): Response<BaseResponse<ScanResult>>

    @POST("patrol/complete")
    suspend fun completePatrol(@Body body: CompleteRequest): Response<BaseResponse<CompleteResult>>

    @POST("patrol/cancel")
    suspend fun cancelPatrol(@Body body: CancelRequest): Response<BaseResponse<com.google.gson.JsonObject>>

    @GET("patrol/history")
    suspend fun patrolHistory(
        @Query("status") status: String? = null,
        @Query("per_page") perPage: Int = 30,
    ): Response<BaseResponse<List<HistoryItem>>>

    @GET("patrol/detail/{sessionCode}")
    suspend fun patrolDetail(@Path("sessionCode") sessionCode: String): Response<BaseResponse<PatrolDetail>>

    // ---------------------------------------------------------- offline sync
    @POST("sync")
    suspend fun sync(@Body body: SyncRequest): Response<BaseResponse<SyncResult>>
}

data class SyncRequest(
    @com.google.gson.annotations.SerializedName("items") val items: List<SyncItem>,
)
