package com.securitypatrol.app.ui.patrol

import android.content.Intent
import android.os.Bundle
import android.view.View
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.securitypatrol.app.R
import com.securitypatrol.app.SecurityPatrolApp
import com.securitypatrol.app.data.PatrolRepository
import com.securitypatrol.app.data.local.SessionManager
import com.securitypatrol.app.data.remote.ApiException
import com.securitypatrol.app.data.remote.dto.PatrolDetail
import com.securitypatrol.app.databinding.ActivityPatrolDetailBinding
import com.securitypatrol.app.util.TimeFormat
import kotlinx.coroutines.launch

/** Detail satu sesi patroli (dari riwayat): info + daftar check-in. */
class PatrolDetailActivity : AppCompatActivity() {

    private lateinit var binding: ActivityPatrolDetailBinding
    private lateinit var adapter: CheckinAdapter

    private val session: SessionManager
        get() = (application as SecurityPatrolApp).session
    private val repo get() = PatrolRepository(session)

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityPatrolDetailBinding.inflate(layoutInflater)
        setContentView(binding.root)

        setSupportActionBar(binding.toolbar)
        supportActionBar?.setDisplayHomeAsUpEnabled(true)
        supportActionBar?.title = getString(R.string.patrol_detail_title)

        adapter = CheckinAdapter()
        binding.rvCheckins.layoutManager = LinearLayoutManager(this)
        binding.rvCheckins.adapter = adapter

        val code = intent.getStringExtra(EXTRA_SESSION_CODE)
        if (code.isNullOrBlank()) {
            toast(getString(R.string.error_title))
            finish()
            return
        }
        load(code)
    }

    private fun load(code: String) {
        lifecycleScope.launch {
            try {
                val d = repo.detail(code)
                render(d)
            } catch (e: ApiException) {
                if (e.errorCode == "UNAUTHENTICATED") {
                    session.logout()
                    startActivity(Intent(this@PatrolDetailActivity, com.securitypatrol.app.ui.login.LoginActivity::class.java))
                    finish()
                } else {
                    toast(e.message ?: getString(R.string.error_title))
                }
            } catch (e: Exception) {
                toast(getString(R.string.error_title))
            }
        }
    }

    private fun render(d: PatrolDetail) {
        binding.tvCode.text = d.sessionCode
        binding.tvOfficer.text = getString(
            R.string.officer_fmt,
            d.officer?.name ?: "-",
        )
        binding.tvRoute.text = getString(R.string.route_fmt, d.route?.name ?: "-")
        binding.tvSchedule.text = getString(R.string.schedule_fmt, d.schedule ?: "-")

        binding.tvStart.text = getString(R.string.time_start_fmt, TimeFormat.full(d.startedAt))
        binding.tvEnd.text = getString(R.string.time_end_fmt, TimeFormat.full(d.completedAt))
        binding.tvDuration.text = getString(
            R.string.duration_short_fmt,
            TimeFormat.duration(d.durationSeconds),
        )
        binding.tvCounter.text = getString(R.string.progress_label, d.completedCheckpoint, d.totalCheckpoint)

        val (bg, fg, label) = when (d.status) {
            "COMPLETED" -> Triple(R.drawable.bg_chip_green, R.color.chip_completed_fg, R.string.status_COMPLETED)
            "RUNNING" -> Triple(R.drawable.bg_chip_orange, R.color.chip_running_fg, R.string.status_RUNNING)
            "INCOMPLETE" -> Triple(R.drawable.bg_chip_red, R.color.chip_incomplete_fg, R.string.status_INCOMPLETE)
            else -> Triple(R.drawable.bg_chip_gray, R.color.chip_pending_fg, R.string.status_CANCELLED)
        }
        binding.chipStatus.setBackgroundResource(bg)
        binding.chipStatus.setTextColor(ContextCompat.getColor(this, fg))
        binding.chipStatus.text = getString(label)

        adapter.submitList(d.checkins)

        val empty = d.checkins.isEmpty()
        binding.rvCheckins.visibility = if (empty) View.GONE else View.VISIBLE
        binding.tvEmpty.visibility = if (empty) View.VISIBLE else View.GONE
    }

    override fun onSupportNavigateUp(): Boolean {
        finish()
        return true
    }

    private fun toast(msg: String) = Toast.makeText(this, msg, Toast.LENGTH_LONG).show()

    companion object {
        const val EXTRA_SESSION_CODE = "session_code"
    }
}
