package com.securitypatrol.app.ui.home

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import com.securitypatrol.app.R
import com.securitypatrol.app.data.remote.dto.HistoryItem
import com.securitypatrol.app.databinding.ItemHistoryBinding
import com.securitypatrol.app.util.TimeFormat

class HistoryAdapter(
    private val onItemClick: (HistoryItem) -> Unit,
) : ListAdapter<HistoryItem, HistoryAdapter.VH>(DIFF) {

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val b = ItemHistoryBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(b)
    }

    override fun onBindViewHolder(holder: VH, position: Int) = holder.bind(getItem(position))

    inner class VH(private val b: ItemHistoryBinding) : RecyclerView.ViewHolder(b.root) {
        fun bind(item: HistoryItem) {
            b.root.setOnClickListener { onItemClick(item) }

            b.tvCode.text = item.sessionCode ?: "-"
            b.tvRoute.text = item.route ?: "-"
            b.tvDate.text = TimeFormat.full(item.startedAt)
            b.tvDuration.text = b.root.context.getString(
                R.string.duration_short_fmt,
                TimeFormat.duration(item.durationSeconds),
            )
            b.tvProgress.text = b.root.context.getString(
                R.string.progress_short_fmt,
                item.completedCheckpoint,
                item.totalCheckpoint,
            )

            val (bg, fg) = when (item.status) {
                "COMPLETED" -> R.drawable.bg_chip_green to R.color.chip_completed_fg
                "RUNNING" -> R.drawable.bg_chip_orange to R.color.chip_running_fg
                "INCOMPLETE" -> R.drawable.bg_chip_red to R.color.chip_incomplete_fg
                else -> R.drawable.bg_chip_gray to R.color.chip_pending_fg
            }
            val ctx = b.root.context
            b.chipStatus.setBackgroundResource(bg)
            b.chipStatus.setTextColor(ContextCompat.getColor(ctx, fg))
            b.chipStatus.text = item.status ?: "-"
        }
    }

    companion object {
        private val DIFF = object : DiffUtil.ItemCallback<HistoryItem>() {
            override fun areItemsTheSame(a: HistoryItem, b: HistoryItem) =
                a.sessionCode == b.sessionCode

            override fun areContentsTheSame(a: HistoryItem, b: HistoryItem) = a == b
        }
    }
}
