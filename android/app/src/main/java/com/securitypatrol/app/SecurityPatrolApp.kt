package com.securitypatrol.app

import android.app.Application
import com.securitypatrol.app.data.local.SessionManager
import com.securitypatrol.app.data.remote.ApiClient
import com.securitypatrol.app.data.sync.SyncWorker

class SecurityPatrolApp : Application() {

    lateinit var session: SessionManager
        private set

    override fun onCreate() {
        super.onCreate()
        session = SessionManager(this)
        ApiClient.init(session)

        // sinkronisasi offline berkala (15 menit) saat koneksi tersedia
        SyncWorker.schedule(this)
    }
}
