package com.noahport.mdm.agent

import android.content.Context
import org.json.JSONObject
import java.io.File
import java.util.UUID

/**
 * Telemetry waiting to be uploaded, one JSON file per batch, so nothing is lost while the phone is offline.
 * Each batch carries a batch_uuid; the server ignores repeats, so a batch is only deleted once it was accepted.
 */
object Outbox {
    private const val DIR = "outbox"
    private const val MAX_FILES = 300 // ~a few days of hourly batches; the oldest go first if the phone stays offline

    /** Queues a batch for POST /api/device/v1/[path]. */
    @Synchronized
    fun add(context: Context, path: String, body: JSONObject) {
        val dir = File(context.filesDir, DIR).apply { mkdirs() }
        body.put("batch_uuid", UUID.randomUUID().toString())
        val name = "${System.currentTimeMillis()}-${path}-${body.getString("batch_uuid")}.json"
        File(dir, name).writeText(JSONObject().put("path", path).put("body", body).toString())

        dir.listFiles()?.sortedBy { it.name }?.dropLast(MAX_FILES)?.forEach { it.delete() }
    }

    /** Uploads queued batches, oldest first. Stops at the first network/server error and keeps the rest for later. */
    fun flush(context: Context, api: ApiClient) {
        val files = File(context.filesDir, DIR).listFiles()?.sortedBy { it.name }.orEmpty()
        for (file in files) {
            val item = runCatching { JSONObject(file.readText()) }.getOrNull()
            if (item == null) {
                file.delete()
                continue
            }
            val code = runCatching { api.post(item.getString("path"), item.getJSONObject("body")).code }.getOrDefault(-1)
            when {
                code in 200..299 -> file.delete()
                code == 422 -> { // the server will never accept this batch: drop it rather than block the queue
                    SpikeLog.log(context, "FAIL upload rejected (422): ${file.name}")
                    file.delete()
                }
                else -> return
            }
        }
    }

    fun size(context: Context): Int = File(context.filesDir, DIR).listFiles()?.size ?: 0
}
