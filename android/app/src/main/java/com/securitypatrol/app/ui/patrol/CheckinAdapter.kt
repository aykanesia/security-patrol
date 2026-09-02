package com.securitypatrol.app.ui.patrol

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import com.securitypatrol.app.R
import com.securitypatrol.app.data.remote.dto.CheckinItem
import com.securitypatrol.app.databinding.ItemCheckinBinding
import com.securitypatrol.app.util.TimeFormat

class CheckinAdapter :
    ListAdapter<CheckinItem, CheckinAdapter.VH>(DIFF) {

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val b = ItemCheckinBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(b)
    }

    override fun onBindViewHolder(holder: VH, position: Int) = holder.bind(getItem(position))

    inner class VH(private val b: ItemCheckinBinding) : RecyclerView.ViewHolder(b.root) {
        fun bind(item: CheckinItem) {
            b.tvName.text = item.checkpoint?.name ?: item.checkpoint?.code ?: "-"
            b.tvCode.text = item.checkpoint?.code ?: "-"
            b.tvTime.text = TimeFormat.full(item.scannedAt)
            b.tvDistance.text = b.root.context.getString(
                R.string.distance_fmt,
                (item.distanceMeter ?: 0.0).toInt(),
            )

            val ctx = b.root.context
            val valid = item.validationStatus == "VALID"
            b.chipStatus.text = item.validationStatus ?: "-"
            b.chipStatus.setBackgroundResource(
                if (valid) R.drawable.bg_chip_green else R.drawable.bg_chip_red,
            )
            b.chipStatus.setTextColor(
                ContextCompat.getColor(
                    ctx,
                    if (valid) R.color.chip_completed_fg else R.color.chip_incomplete_fg,
                ),
            )
        }
    }

    companion object {
        private val DIFF = object : DiffUtil.ItemCallback<CheckinItem>() {
            override fun areItemsTheSame(a: CheckinItem, b: CheckinItem) =
                a.checkpoint?.code == b.checkpoint?.code && a.scannedAt == b.scannedAt

            override fun areContentsTheSame(a: CheckinItem, b: CheckinItem) = a == b
        }
    }
}
