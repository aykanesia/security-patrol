package com.securitypatrol.app.ui.patrol

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import com.securitypatrol.app.R
import com.securitypatrol.app.data.remote.dto.CurrentCheckpoint
import com.securitypatrol.app.databinding.ItemCheckpointBinding

class CheckpointAdapter :
    ListAdapter<CurrentCheckpoint, CheckpointAdapter.VH>(DIFF) {

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val b = ItemCheckpointBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(b)
    }

    override fun onBindViewHolder(holder: VH, position: Int) = holder.bind(getItem(position))

    inner class VH(private val b: ItemCheckpointBinding) : RecyclerView.ViewHolder(b.root) {
        fun bind(item: CurrentCheckpoint) {
            b.tvName.text = item.name ?: item.code ?: "-"
            b.tvCode.text = b.root.context.getString(
                R.string.cp_code_seq_fmt,
                item.code.orEmpty(),
                item.sequence,
            )
            b.tvOrder.text = item.sequence.toString()

            val completed = item.status == "COMPLETED"
            val ctx = b.root.context

            b.ivDot.setColorFilter(
                ContextCompat.getColor(
                    ctx,
                    if (completed) R.color.brand_accent else R.color.chip_pending_fg,
                ),
            )

            b.chipStatus.text = ctx.getString(
                if (completed) R.string.cp_COMPLETED else R.string.cp_PENDING,
            )
            b.chipStatus.setBackgroundResource(
                if (completed) R.drawable.bg_chip_green else R.drawable.bg_chip_gray,
            )
            b.chipStatus.setTextColor(
                ContextCompat.getColor(
                    ctx,
                    if (completed) R.color.chip_completed_fg else R.color.chip_pending_fg,
                ),
            )

            b.ivChecked.visibility = if (completed) View.VISIBLE else View.GONE
        }
    }

    companion object {
        private val DIFF = object : DiffUtil.ItemCallback<CurrentCheckpoint>() {
            override fun areItemsTheSame(a: CurrentCheckpoint, b: CurrentCheckpoint) = a.id == b.id
            override fun areContentsTheSame(a: CurrentCheckpoint, b: CurrentCheckpoint) = a == b
        }
    }
}
