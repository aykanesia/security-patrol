package com.securitypatrol.app.ui.patrol

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Bundle
import android.view.View
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.zxing.integration.android.IntentIntegrator
import com.securitypatrol.app.R
import com.securitypatrol.app.SecurityPatrolApp
import com.securitypatrol.app.data.PatrolRepository
import com.securitypatrol.app.data.local.OfflineQueue
import com.securitypatrol.app.data.local.SessionManager
import com.securitypatrol.app.data.remote.ApiException
import com.securitypatrol.app.data.remote.dto.CurrentPatrol
import com.securitypatrol.app.data.remote.dto.SyncItem
import com.securitypatrol.app.data.sync.SyncWorker
import com.securitypatrol.app.databinding.ActivityPatrolBinding
import com.securitypatrol.app.util.LocationHelper
import com.securitypatrol.app.util.TimeFormat
import kotlinx.coroutines.launch

/**
 * Layar patroli berjalan: info sesi + progress + daftar checkpoint,
 * tombol SCAN (QR + GPS), SELESAI PATROLI, dan Batalkan.
 */
class PatrolActivity : AppCompatActivity() {

    private lateinit var binding: ActivityPatrolBinding
    private lateinit var adapter: CheckpointAdapter
    private lateinit var location: LocationHelper

    private val session: SessionManager
        get() = (application as SecurityPatrolApp).session
    private val repo get() = PatrolRepository(session)

    private var current: CurrentPatrol? = null
    private var isScanning = false

    private val permissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { grants ->
            val loc = grants[Manifest.permission.ACCESS_FINE_LOCATION] == true ||
                grants[Manifest.permission.ACCESS_COARSE_LOCATION] == true
            val cam = grants[Manifest.permission.CAMERA] == true
            when {
                !loc -> toast(getString(R.string.toast_location_needed))
                !cam -> toast(getString(R.string.need_camera_permission))
                else -> launchScanner()
            }
        }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityPatrolBinding.inflate(layoutInflater)
        setContentView(binding.root)
        location = LocationHelper(this)

        setSupportActionBar(binding.toolbar)
        supportActionBar?.setDisplayHomeAsUpEnabled(true)
        supportActionBar?.title = getString(R.string.patrol_active_title)

        adapter = CheckpointAdapter()
        binding.rvCheckpoints.layoutManager = LinearLayoutManager(this)
        binding.rvCheckpoints.adapter = adapter

        binding.btnScan.setOnClickListener { onScanClick() }
        binding.btnComplete.setOnClickListener { onCompleteClick() }
        binding.btnCancel.setOnClickListener { onCancelClick() }

        load()
    }

    private fun load() {
        lifecycleScope.launch {
            try {
                val data = repo.currentPatrol()
                if (data?.session?.status != "RUNNING") {
                    // tidak ada patroli aktif — kembali
                    toast(getString(R.string.patrol_no_active_msg))
                    finish()
                    return@launch
                }
                current = data
                render(data)
            } catch (e: ApiException) {
                if (e.errorCode == "UNAUTHENTICATED") {
                    session.logout()
                    startActivity(Intent(this@PatrolActivity, com.securitypatrol.app.ui.login.LoginActivity::class.java))
                    finish()
                } else {
                    toast(e.message ?: getString(R.string.error_title))
                    finish()
                }
            } catch (e: Exception) {
                toast(getString(R.string.error_title))
                finish()
            }
        }
    }

    private fun render(p: CurrentPatrol) {
        val s = p.session ?: return

        binding.tvSessionCode.text = s.sessionCode
        binding.tvRoute.text = p.route?.name ?: "-"
        binding.tvArea.text = p.route?.area ?: ""
        binding.tvStartTime.text = getString(R.string.time_start_fmt, TimeFormat.full(s.startedAt))
        binding.tvCounter.text = getString(R.string.progress_label, s.completedCheckpoint, s.totalCheckpoint)

        binding.progressBar.max = if (s.totalCheckpoint > 0) s.totalCheckpoint else 1
        binding.progressBar.progress = s.completedCheckpoint
        binding.tvPercent.text = "${p.progressPercentage}%"

        adapter.submitList(p.checkpoints)

        val done = s.completedCheckpoint >= s.totalCheckpoint && s.totalCheckpoint > 0
        binding.btnComplete.isEnabled = done
        binding.btnComplete.alpha = if (done) 1f else 0.5f
    }

    // ------------------------------------------------------------- scan
    private fun onScanClick() {
        if (isScanning) return
        val needLoc = ContextCompat.checkSelfPermission(
            this, Manifest.permission.ACCESS_FINE_LOCATION,
        ) != PackageManager.PERMISSION_GRANTED
        val needCam = ContextCompat.checkSelfPermission(
            this, Manifest.permission.CAMERA,
        ) != PackageManager.PERMISSION_GRANTED

        if (needLoc || needCam) {
            permissionLauncher.launch(
                arrayOf(
                    Manifest.permission.ACCESS_FINE_LOCATION,
                    Manifest.permission.ACCESS_COARSE_LOCATION,
                    Manifest.permission.CAMERA,
                ),
            )
        } else {
            launchScanner()
        }
    }

    private fun launchScanner() {
        IntentIntegrator(this)
            .setDesiredBarcodeFormats(IntentIntegrator.QR_CODE)
            .setPrompt(getString(R.string.scan_prompt))
            .setBeepEnabled(true)
            .initiateScan()
    }

    @Deprecated("Deprecated in Java")
    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode == IntentIntegrator.REQUEST_CODE) {
            val result = IntentIntegrator.parseActivityResult(requestCode, resultCode, data)
            if (result != null && result.contents != null) {
                submitScan(result.contents)
            } else {
                toast(getString(R.string.toast_scan_cancelled))
            }
        }
    }

    private fun submitScan(qr: String) {
        val sessionCode = current?.session?.sessionCode ?: return
        val patrol = current ?: return
        isScanning = true
        setScanBusy(true)

        lifecycleScope.launch {
            try {
                if (!location.hasPermission) {
                    toast(getString(R.string.toast_location_needed))
                    return@launch
                }
                val loc = location.getCurrentLocation()
                if (loc == null) {
                    toast(getString(R.string.toast_location_fail))
                    return@launch
                }
                val acc = if (loc.hasAccuracy()) loc.accuracy.toDouble() else 0.0

                val res = repo.scan(sessionCode, qr, loc.latitude, loc.longitude, acc)
                val name = res.checkpoint?.name ?: res.checkpoint?.code ?: qr
                val dist = (res.distanceMeter ?: 0.0).toInt()
                toast(getString(R.string.msg_scan_valid, name, dist))
                load() // refresh progress
            } catch (e: ApiException) {
                handleScanError(e)
            } catch (e: Exception) {
                // offline / error jaringan → simpan ke antrian offline bersama koordinat asli
                enqueueOffline(sessionCode, qr, patrol)
            } finally {
                isScanning = false
                setScanBusy(false)
            }
        }
    }

    private fun handleScanError(e: ApiException) {
        when (e.errorCode) {
            "INVALID_LOCATION" -> toast(e.message ?: getString(R.string.msg_invalid_location))
            "DUPLICATE_CHECKIN" -> toast(getString(R.string.msg_duplicate))
            "INVALID_SEQUENCE" -> toast(e.message ?: getString(R.string.msg_invalid_sequence))
            "INVALID_CHECKPOINT" -> toast(e.message ?: getString(R.string.msg_invalid_checkpoint))
            "ALREADY_PROCESSED" -> {
                toast(getString(R.string.msg_already_processed))
                load()
            }
            "SESSION_NOT_RUNNING" -> {
                toast(e.message ?: getString(R.string.msg_session_ended))
                finish()
            }
            "UNAUTHENTICATED" -> {
                session.logout()
                startActivity(Intent(this@PatrolActivity, com.securitypatrol.app.ui.login.LoginActivity::class.java))
                finish()
            }
            else -> toast(e.message ?: getString(R.string.error_title))
        }
    }

    /** Simpan scan yang gagal terkirim (offline) → dikirim otomatis saat online. */
    private fun enqueueOffline(sessionCode: String, qr: String, patrol: CurrentPatrol) {
        val target = patrol.checkpoints.firstOrNull { it.code == qr || qr.contains(it.code ?: "") }
        val lat = target?.latitude ?: 0.0
        val lon = target?.longitude ?: 0.0
        val queue = OfflineQueue(applicationContext)
        queue.enqueue(
            SyncItem(
                uuid = java.util.UUID.randomUUID().toString(),
                sessionCode = sessionCode,
                checkpointCode = qr,
                latitude = lat,
                longitude = lon,
                gpsAccuracy = 0.0,
                deviceTimestamp = TimeFormat.nowServer(),
                deviceUuid = session.deviceUuid,
            ),
        )
        SyncWorker.syncNow(applicationContext)
        toast(getString(R.string.msg_offline_saved))
    }

    // ----------------------------------------------------------- complete
    private fun onCompleteClick() {
        val p = current ?: return
        val code = p.session?.sessionCode ?: return

        MaterialAlertDialogBuilder(this)
            .setTitle(getString(R.string.confirm_complete_title))
            .setMessage(getString(R.string.confirm_complete_msg))
            .setPositiveButton(getString(R.string.btn_complete)) { _, _ ->
                doComplete(code)
            }
            .setNegativeButton(getString(R.string.btn_cancel), null)
            .show()
    }

    private fun doComplete(code: String) {
        lifecycleScope.launch {
            try {
                if (!location.hasPermission) {
                    toast(getString(R.string.toast_location_needed))
                    return@launch
                }
                val loc = location.getCurrentLocation()
                val lat = loc?.latitude ?: 0.0
                val lon = loc?.longitude ?: 0.0
                val res = repo.complete(code, lat, lon)
                toast(getString(R.string.msg_complete_ok))
                finish()
            } catch (e: ApiException) {
                when (e.errorCode) {
                    "CHECKPOINT_INCOMPLETE" -> toast(getString(R.string.msg_need_all_cp))
                    else -> toast(e.message ?: getString(R.string.error_title))
                }
            } catch (e: Exception) {
                toast(getString(R.string.error_title))
            }
        }
    }

    private fun onCancelClick() {
        val code = current?.session?.sessionCode ?: return
        MaterialAlertDialogBuilder(this)
            .setTitle(getString(R.string.confirm_cancel_title))
            .setMessage(getString(R.string.confirm_cancel_msg))
            .setPositiveButton(getString(R.string.dialog_ok)) { _, _ ->
                lifecycleScope.launch {
                    try {
                        repo.cancel(code)
                        toast(getString(R.string.msg_cancel_ok))
                        finish()
                    } catch (e: Exception) {
                        toast(getString(R.string.error_title))
                    }
                }
            }
            .setNegativeButton(getString(R.string.btn_cancel), null)
            .show()
    }

    private fun setScanBusy(busy: Boolean) {
        binding.btnScan.isEnabled = !busy
        binding.btnScan.text = if (busy) {
            getString(R.string.scan_processing)
        } else {
            getString(R.string.btn_scan)
        }
    }

    override fun onSupportNavigateUp(): Boolean {
        finish()
        return true
    }

    private fun toast(msg: String) = Toast.makeText(this, msg, Toast.LENGTH_LONG).show()
}
