package com.securitypatrol.app.data.sync

import android.content.Context
import androidx.work.*
import com.securitypatrol.app.data.PatrolRepository
import com.securitypatrol.app.data.local.OfflineQueue
import com.securitypatrol.app.data.local.SessionManager
import com.securitypatrol.app.data.remote.dto.SyncItem
import java.util.concurrent.TimeUnit

/**
 * Mengirim antrian offline ke POST /sync.
 * Hapus item yang status success/duplicate, tahan yang failed (invalid
 * secara bisnis — perlu ditampilkan ke petugas, bukan dihapus).
 */
class SyncWorker(
    context: Context,
    params: WorkerParameters,
) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val app = applicationContext
        val session = SessionManager(app)
        if (!session.isLoggedIn) return Result.success() // belum login, tidak ada yg bisa disinkron

        val queue = OfflineQueue(app)
        val pending = queue.all()
        if (pending.isEmpty()) return Result.success()

        val repository = PatrolRepository(session)
        return try {
            val result = repository.syncBatch(pending)
            val done = result.items
                .filter { it.status == "success" || it.status == "duplicate" }
                .mapNotNull { it.uuid }
                .toSet()
            queue.remove(done)

            // sisa yang belum terkirim (gagal jaringan/invalid) tetap di antrian;
            // worker periodik 15 menit + syncNow() akan mencoba lagi.
            Result.success()
        } catch (e: Exception) {
            if (repository.isNetworkError(e)) Result.retry() else Result.success()
        }
    }

    companion object {
        private const val WORK_NAME = "patrol_offline_sync"

        fun schedule(context: Context) {
            val request = PeriodicWorkRequestBuilder<SyncWorker>(15, TimeUnit.MINUTES)
                .setConstraints(
                    Constraints.Builder()
                        .setRequiredNetworkType(NetworkType.CONNECTED)
                        .build(),
                )
                .build()

            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                WORK_NAME,
                ExistingPeriodicWorkPolicy.KEEP,
                request,
            )
        }

        /** Panggil setelah scan offline masuk — kirim segera bila memungkinkan. */
        fun syncNow(context: Context) {
            val request = OneTimeWorkRequestBuilder<SyncWorker>()
                .setConstraints(
                    Constraints.Builder()
                        .setRequiredNetworkType(NetworkType.CONNECTED)
                        .build(),
                )
                .build()
            WorkManager.getInstance(context).enqueueUniqueWork(
                "patrol_offline_sync_now",
                ExistingWorkPolicy.REPLACE,
                request,
            )
        }
    }
}
