package com.securitypatrol.app.ui.home

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import com.securitypatrol.app.R
import com.securitypatrol.app.data.remote.dto.TodaySchedule
import com.securitypatrol.app.databinding.ItemScheduleBinding
import com.securitypatrol.app.util.TimeFormat

class ScheduleAdapter(
    private val onStartClick: (TodaySchedule) -> Unit,
) : ListAdapter<TodaySchedule, ScheduleAdapter.VH>(DIFF) {

    enum class Mode { IDLE, RUNNING }

    var mode: Mode = Mode.IDLE
        set(value) {
            if (field != value) {
                field = value
                notifyDataSetChanged()
            }
        }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val b = ItemScheduleBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(b)
    }

    override fun onBindViewHolder(holder: VH, position: Int) = holder.bind(getItem(position))

    inner class VH(private val b: ItemScheduleBinding) : RecyclerView.ViewHolder(b.root) {
        fun bind(item: TodaySchedule) {
            b.tvName.text = item.name ?: "Jadwal"
            b.tvTime.text = TimeFormat.range(item.startTime?.take(5), item.endTime?.take(5))
            b.tvRoute.text = item.route?.name ?: "-"
            b.tvArea.text = item.route?.area ?: ""
            b.tvCount.text = b.root.context.getString(
                R.string.cp_count_fmt,
                item.route?.totalCheckpoint ?: 0,
            )

            if (mode == Mode.RUNNING) {
                b.btnStart.text = b.root.context.getString(R.string.btn_resume)
            } else {
                b.btnStart.text = b.root.context.getString(R.string.btn_start_patrol)
            }
            b.btnStart.setOnClickListener { onStartClick(item) }
        }
    }

    companion object {
        private val DIFF = object : DiffUtil.ItemCallback<TodaySchedule>() {
            override fun areItemsTheSame(a: TodaySchedule, b: TodaySchedule) = a.id == b.id
            override fun areContentsTheSame(a: TodaySchedule, b: TodaySchedule) = a == b
        }
    }
}
