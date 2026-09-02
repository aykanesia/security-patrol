package com.securitypatrol.app.util

import android.annotation.SuppressLint
import android.content.Context
import android.content.pm.PackageManager
import android.location.Location
import androidx.core.content.ContextCompat
import com.google.android.gms.location.LocationServices
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlin.coroutines.resume

/**
 * Pembungkus FusedLocationProvider — mengambil posisi GPS terakhir/terbaru.
 * Pastikan izin ACCESS_FINE_LOCATION sudah diberikan sebelum dipanggil.
 */
class LocationHelper(private val context: Context) {

    private val client = LocationServices.getFusedLocationProviderClient(context)

    val hasPermission: Boolean
        get() = ContextCompat.checkSelfPermission(
            context,
            android.Manifest.permission.ACCESS_FINE_LOCATION,
        ) == PackageManager.PERMISSION_GRANTED

    @SuppressLint("MissingPermission")
    suspend fun getCurrentLocation(timeoutMs: Long = 12_000): Location? =
        suspendCancellableCoroutine { cont ->
            fun done(loc: Location?) {
                if (cont.isActive) cont.resume(loc)
            }
            try {
                client.lastLocation
                    .addOnSuccessListener { last ->
                        if (last != null) {
                            done(last)
                        } else {
                            // lastLocation null (GPS baru nyala) → minta update sekali
                            client.getCurrentLocation(
                                com.google.android.gms.location.Priority.PRIORITY_HIGH_ACCURACY,
                                null,
                            )
                                .addOnSuccessListener { loc -> done(loc) }
                                .addOnFailureListener { done(null) }
                        }
                    }
                    .addOnFailureListener { done(null) }
            } catch (e: Exception) {
                done(null)
            }
        }
}

object TimeFormat {

    private val server = java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss", java.util.Locale.US)
    private val clock = java.text.SimpleDateFormat("HH:mm", java.util.Locale.getDefault())
    private val full = java.text.SimpleDateFormat("dd MMM yyyy HH:mm", java.util.Locale("id", "ID"))

    fun parse(s: String?): java.util.Date? =
        runCatching { server.parse(s ?: return null) }.getOrNull()

    fun nowServer(): String = server.format(java.util.Date())

    fun time(s: String?): String {
        val d = parse(s) ?: return s ?: "-"
        return clock.format(d)
    }

    fun full(s: String?): String {
        val d = parse(s) ?: return s ?: "-"
        return full.format(d)
    }

    fun duration(seconds: Long?): String {
        if (seconds == null) return "-"
        val h = seconds / 3600
        val m = (seconds % 3600) / 60
        val s = seconds % 60
        return if (h > 0) "%dh %02dm".format(h, m)
        else if (m > 0) "%dm %02ds".format(m, s)
        else "%ds".format(s)
    }

    /** rentang "22:00 - 23:00" */
    fun range(start: String?, end: String?): String =
        (start ?: "-") + " - " + (end ?: "-")
}
