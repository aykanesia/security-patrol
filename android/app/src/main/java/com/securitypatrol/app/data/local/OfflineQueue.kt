package com.securitypatrol.app.data.local

import android.content.Context
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import com.securitypatrol.app.data.remote.dto.SyncItem

/**
 * Antrian check-in offline. Saat scan gagal dikirim (tidak ada internet),
 * item disimpan di sini dan akan dikirim otomatis oleh SyncWorker
 * (WorkManager) begitu koneksi pulih.
 */
class OfflineQueue(context: Context) {

    private val prefs = context.getSharedPreferences("sp_offline", Context.MODE_PRIVATE)
    private val gson = Gson()

    @Synchronized
    fun enqueue(item: SyncItem) {
        val all = all().toMutableList()
        if (all.none { it.uuid == item.uuid }) {
            all.add(item)
            save(all)
        }
    }

    @Synchronized
    fun all(): List<SyncItem> {
        val raw = prefs.getString(KEY_ITEMS, null) ?: return emptyList()
        return runCatching {
            gson.fromJson<List<SyncItem>>(raw, object : TypeToken<List<SyncItem>>() {}.type)
                ?: emptyList()
        }.getOrDefault(emptyList())
    }

    @Synchronized
    fun remove(uuids: Set<String>) {
        save(all().filterNot { it.uuid in uuids })
    }

    @Synchronized
    fun clear() {
        save(emptyList())
    }

    @Synchronized
    val size: Int get() = all().size

    private fun save(items: List<SyncItem>) {
        prefs.edit().putString(KEY_ITEMS, gson.toJson(items)).apply()
    }

    companion object {
        private const val KEY_ITEMS = "pending_items"
    }
}
