package com.securitypatrol.app.ui.home

import android.content.Intent
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.Toast
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.securitypatrol.app.R
import com.securitypatrol.app.SecurityPatrolApp
import com.securitypatrol.app.data.PatrolRepository
import com.securitypatrol.app.data.local.SessionManager
import com.securitypatrol.app.data.remote.ApiException
import com.securitypatrol.app.data.remote.dto.HistoryItem
import com.securitypatrol.app.databinding.FragmentHistoryBinding
import com.securitypatrol.app.ui.patrol.PatrolDetailActivity
import kotlinx.coroutines.launch

class HistoryFragment : Fragment() {

    private var _binding: FragmentHistoryBinding? = null
    private val binding get() = _binding!!
    private lateinit var adapter: HistoryAdapter

    private val session: SessionManager
        get() = (requireContext().applicationContext as SecurityPatrolApp).session
    private val repo get() = PatrolRepository(session)

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?,
    ): View {
        _binding = FragmentHistoryBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        adapter = HistoryAdapter(onItemClick = { item -> openDetail(item) })
        binding.rvHistory.layoutManager = LinearLayoutManager(requireContext())
        binding.rvHistory.adapter = adapter
    }

    override fun onResume() {
        super.onResume()
        load()
    }

    private fun load() {
        lifecycleScope.launch {
            try {
                val items = repo.history()
                adapter.submit(items)
                binding.rvHistory.visibility = if (items.isEmpty()) View.GONE else View.VISIBLE
                binding.tvEmpty.visibility = if (items.isEmpty()) View.VISIBLE else View.GONE
            } catch (e: ApiException) {
                if (e.errorCode == "UNAUTHENTICATED") {
                    session.logout()
                    startActivity(Intent(requireContext(), com.securitypatrol.app.ui.login.LoginActivity::class.java))
                    requireActivity().finish()
                } else {
                    toast(e.message ?: getString(R.string.error_title))
                }
            } catch (e: Exception) {
                toast(getString(R.string.error_title))
            }
        }
    }

    private fun openDetail(item: HistoryItem) {
        val code = item.sessionCode ?: return
        startActivity(
            Intent(requireContext(), PatrolDetailActivity::class.java)
                .putExtra(PatrolDetailActivity.EXTRA_SESSION_CODE, code),
        )
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }

    private fun toast(msg: String) = Toast.makeText(requireContext(), msg, Toast.LENGTH_LONG).show()
}
