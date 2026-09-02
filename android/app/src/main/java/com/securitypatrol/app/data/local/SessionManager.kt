package com.securitypatrol.app.data.local

import android.content.Context
import android.content.SharedPreferences
import java.util.UUID

/**
 * Menyimpan token, profil user, dan device_uuid unik per instalasi.
 * device_uuid dibuat sekali (UUID v4) dan dipakai di seluruh request
 * (login/start/scan) supaya backend mengenali perangkat.
 */
class SessionManager(context: Context) {

    private val prefs: SharedPreferences =
        context.getSharedPreferences("sp_session", Context.MODE_PRIVATE)

    var token: String?
        get() = prefs.getString(KEY_TOKEN, null)
        set(value) = prefs.edit().putString(KEY_TOKEN, value).apply()

    val user: LoggedUser?
        get() {
            val raw = prefs.getString(KEY_USER, null) ?: return null
            return runCatching {
                val parts = raw.split("|")
                LoggedUser(
                    id = parts.getOrNull(0)?.toLongOrNull() ?: 0L,
                    name = parts.getOrNull(1).orEmpty(),
                    username = parts.getOrNull(2).orEmpty(),
                    employeeCode = parts.getOrNull(3).orEmpty(),
                    role = parts.getOrNull(4).orEmpty(),
                )
            }.getOrNull()
        }

    fun saveSession(token: String, user: LoggedUser) {
        prefs.edit()
            .putString(KEY_TOKEN, token)
            .putString(
                KEY_USER,
                "${user.id}|${user.name}|${user.username}|${user.employeeCode}|${user.role}",
            )
            .apply()
    }

    val isLoggedIn: Boolean get() = !token.isNullOrBlank()

    /** UUID unik per instalasi — untuk kolom device_uuid di backend. */
    val deviceUuid: String
        get() {
            prefs.getString(KEY_DEVICE_UUID, null)?.let { return it }
            val newUuid = UUID.randomUUID().toString()
            prefs.edit().putString(KEY_DEVICE_UUID, newUuid).apply()
            return newUuid
        }

    fun logout() {
        prefs.edit().clear().apply()
    }

    data class LoggedUser(
        val id: Long,
        val name: String,
        val username: String,
        val employeeCode: String,
        val role: String,
    )

    companion object {
        private const val KEY_TOKEN = "token"
        private const val KEY_USER = "user"
        private const val KEY_DEVICE_UUID = "device_uuid"
    }
}
