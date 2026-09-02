package com.securitypatrol.app.data.remote

import com.securitypatrol.app.BuildConfig
import com.securitypatrol.app.data.local.SessionManager
import okhttp3.Interceptor
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

/** Exception hasil parsing envelope error backend (success:false). */
class ApiException(
    val errorCode: String?,
    val httpCode: Int,
    message: String?,
) : Exception(message ?: "Terjadi kesalahan ($httpCode)")

object ApiClient {

    lateinit var api: PatrolApi
        private set

    fun init(session: SessionManager) {
        val authInterceptor = Interceptor { chain ->
            val token = session.token
            val req = chain.request().newBuilder()
            if (!token.isNullOrBlank()) {
                req.header("Authorization", "Bearer $token")
            }
            req.header("Accept", "application/json")
            chain.proceed(req.build())
        }

        val logging = HttpLoggingInterceptor().apply {
            level = if (BuildConfig.DEBUG) {
                HttpLoggingInterceptor.Level.BASIC
            } else {
                HttpLoggingInterceptor.Level.NONE
            }
        }

        val client = OkHttpClient.Builder()
            .addInterceptor(authInterceptor)
            .addInterceptor(logging)
            .connectTimeout(20, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .writeTimeout(30, TimeUnit.SECONDS)
            .build()

        val retrofit = Retrofit.Builder()
            .baseUrl(BuildConfig.API_BASE_URL.trimEnd('/') + "/")
            .client(client)
            .addConverterFactory(GsonConverterFactory.create())
            .build()

        api = retrofit.create(PatrolApi::class.java)
    }

    /** Buang kredensial lama & build ulang client setelah logout. */
    fun reinit(session: SessionManager) = init(session)
}
