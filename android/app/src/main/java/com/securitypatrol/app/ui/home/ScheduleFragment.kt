package com.securitypatrol.app.ui.home

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.securitypatrol.app.R
import com.securitypatrol.app.SecurityPatrolApp
import com.securitypatrol.app.data.PatrolRepository
import com.securitypatrol.app.data.local.SessionManager
import com.securitypatrol.app.data.remote.ApiException
import com.securitypatrol.app.data.remote.dto.CurrentPatrol
import com.securitypatrol.app.data.remote.dto.TodaySchedule
import com.securitypatrol.app.databinding.FragmentScheduleBinding
import com.securitypatrol.app.ui.patrol.PatrolActivity
import com.securitypatrol.app.util.LocationHelper
import com.securitypatrol.app.util.TimeFormat
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * Tab "Jadwal Hari Ini": banner patroli berjalan (bila ada) + daftar jadwal.
 * Reload tiap kali tab terlihat agar selalu sinkron dengan backend.
 */
class ScheduleFragment : Fragment() {

    private var _binding: FragmentScheduleBinding? = null
    private val binding get() = _binding!!
    private lateinit var adapter: ScheduleAdapter
    private lateinit var location: LocationHelper

    private val session: SessionManager
        get() = (requireContext().applicationContext as SecurityPatrolApp).session
    private val repo get() = PatrolRepository(session)

    private val permissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { grants ->
            val loc = grants[Manifest.permission.ACCESS_FINE_LOCATION] == true ||
                grants[Manifest.permission.ACCESS_COARSE_LOCATION] == true
            if (loc) {
                pendingSchedule?.let { doStartPatrol(it) }
            } else {
                toast(getString(R.string.toast_location_needed))
            }
            pendingSchedule = null
        }

    /** jadwal yang menunggu GPS setelah izin diberikan */
    private var pendingSchedule: TodaySchedule? = null

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?,
    ): View {
        _binding = FragmentScheduleBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        location = LocationHelper(requireContext())

        binding.tvTodayDate.text = getString(
            R.string.date_today_fmt,
            SimpleDateFormat("EEEE", Locale("id", "ID")).format(Date()),
            SimpleDateFormat("d MMMM yyyy", Locale("id", "ID")).format(Date()),
        )

        adapter = ScheduleAdapter(onStartClick = { schedule -> onStartClicked(schedule) })
        binding.rvSchedules.layoutManager = LinearLayoutManager(requireContext())
        binding.rvSchedules.adapter = adapter

        binding.bannerRunning.setOnClickListener { openPatrol() }
    }

    override fun onResume() {
        super.onResume()
        load()
    }

    private fun load() {
        lifecycleScope.launch {
            try {
                val running = repo.currentPatrol()
                val schedules = repo.todaySchedules()
                render(running, schedules)
            } catch (e: ApiException) {
                if (e.errorCode == "UNAUTHENTICATED") {
                    session.logout()
                    goLogin()
                } else {
                    toast(e.message ?: getString(R.string.error_title))
                }
            } catch (e: Exception) {
                toast(getString(R.string.error_title))
            }
        }
    }

    private fun render(running: CurrentPatrol?, schedules: List<TodaySchedule>) {
        if (running?.session?.status == "RUNNING") {
            binding.bannerRunning.visibility = View.VISIBLE
            binding.tvBannerTitle.text = getString(R.string.running_banner_title)
            binding.tvBannerSubtitle.text = getString(
                R.string.running_banner_subtitle,
                running.session?.sessionCode.orEmpty(),
                running.session?.completedCheckpoint ?: 0,
                running.session?.totalCheckpoint ?: 0,
            )
            adapter.mode = ScheduleAdapter.Mode.RUNNING
        } else {
            binding.bannerRunning.visibility = View.GONE
            adapter.mode = ScheduleAdapter.Mode.IDLE
        }
        adapter.submit(schedules)

        val empty = schedules.isEmpty()
        binding.rvSchedules.visibility = if (empty) View.GONE else View.VISIBLE
        binding.tvEmpty.visibility = if (empty) View.VISIBLE else View.GONE
    }

    // ------------------------------------------------------------ start flow
    private fun onStartClicked(schedule: TodaySchedule) {
        // sedang ada patroli berjalan → tombol berarti "lanjut", buka langsung
        if (adapter.mode == ScheduleAdapter.Mode.RUNNING) {
            openPatrol()
            return
        }

        val needLoc = ContextCompat.checkSelfPermission(
            requireContext(), Manifest.permission.ACCESS_FINE_LOCATION,
        ) != PackageManager.PERMISSION_GRANTED

        if (needLoc) {
            pendingSchedule = schedule
            permissionLauncher.launch(
                arrayOf(
                    Manifest.permission.ACCESS_FINE_LOCATION,
                    Manifest.permission.ACCESS_COARSE_LOCATION,
                ),
            )
        } else {
            lifecycleScope.launch { doStartPatrol(schedule) }
        }
    }

    private suspend fun doStartPatrol(schedule: TodaySchedule) {
        try {
            val loc = location.getCurrentLocation()
            if (loc == null) {
                toast(getString(R.string.toast_location_fail))
                return
            }
            val started = repo.startPatrol(schedule.id, loc.latitude, loc.longitude)
            toast("${started.sessionCode} — ${getString(R.string.patrol_started_msg)}")
            openPatrol()
        } catch (e: ApiException) {
            if (e.errorCode == "SESSION_ALREADY_RUNNING") {
                openPatrol() // sudah ada sesi berjalan → buka layar patroli
            } else {
                toast(e.message ?: getString(R.string.error_title))
            }
        } catch (e: Exception) {
            toast(getString(R.string.error_title))
        }
    }

    private fun openPatrol() {
        startActivity(Intent(requireContext(), PatrolActivity::class.java))
    }

    private fun goLogin() {
        startActivity(Intent(requireContext(), com.securitypatrol.app.ui.login.LoginActivity::class.java))
        requireActivity().finish()
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }

    private fun toast(msg: String) = Toast.makeText(requireContext(), msg, Toast.LENGTH_LONG).show()
}
