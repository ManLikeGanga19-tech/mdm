package com.noahport.mdm.agent

import android.app.usage.NetworkStats
import android.app.usage.NetworkStatsManager
import android.content.Context
import android.net.ConnectivityManager
import org.json.JSONArray
import org.json.JSONObject
import java.time.Duration
import java.time.Instant
import java.time.temporal.ChronoUnit

/**
 * Data used per app per network per hour, from NetworkStatsManager (device owners see every app, including
 * tethering, without usage access; confirmed in the Phase 1 spike). Android keeps per-app history in ~2-hour buckets
 * and pro-rates them over a query window, so hourly figures are approximate within a bucket but add up correctly.
 */
class UsageCollector(private val context: Context) {

    private val prefs = context.getSharedPreferences("usage", Context.MODE_PRIVATE)

    /** Queues completed hours since the last run (re-sending the last 2 hours, which Android may still update). */
    fun collect() {
        val currentHour = Instant.now().truncatedTo(ChronoUnit.HOURS)
        val lastDone = prefs.getLong(KEY_LAST_HOUR, 0L).takeIf { it > 0 }?.let { Instant.ofEpochMilli(it) }
        var hour = maxOf(
            (lastDone ?: currentHour.minus(Duration.ofHours(24))).minus(Duration.ofHours(2)),
            currentHour.minus(Duration.ofHours(MAX_BACKLOG_HOURS)),
        )

        val samples = JSONArray()
        while (hour.isBefore(currentHour)) {
            val end = hour.plus(Duration.ofHours(1))
            for ((network, type) in NETWORKS) collectHour(network, type, hour, end, samples)
            hour = end
        }
        if (samples.length() > 0) Outbox.add(context, "usage", JSONObject().put("samples", samples))
        prefs.edit().putLong(KEY_LAST_HOUR, currentHour.toEpochMilli()).apply()
    }

    // TYPE_MOBILE / TYPE_WIFI are deprecated but remain the documented inputs to querySummary.
    @Suppress("DEPRECATION")
    private fun collectHour(network: String, type: Int, start: Instant, end: Instant, out: JSONArray) {
        val nsm = context.getSystemService(NetworkStatsManager::class.java)
        val perUid = mutableMapOf<Int, LongArray>()
        runCatching {
            nsm.querySummary(type, null, start.toEpochMilli(), end.toEpochMilli()).use { stats ->
                val bucket = NetworkStats.Bucket()
                while (stats.hasNextBucket()) {
                    stats.getNextBucket(bucket)
                    val totals = perUid.getOrPut(bucket.uid) { LongArray(2) }
                    totals[0] += bucket.rxBytes
                    totals[1] += bucket.txBytes
                }
            }
        }.onFailure { SpikeLog.log(context, "FAIL usage $network $start: $it") }

        for ((uid, totals) in perUid) {
            if (totals[0] + totals[1] == 0L) continue
            out.put(
                JSONObject()
                    .put("package", label(uid))
                    .put("network", network)
                    .put("rx", totals[0])
                    .put("tx", totals[1])
                    .put("start", start.toString())
                    .put("end", end.toString()),
            )
        }
    }

    /** Package name for a UID; sentinels for shared/unknown UIDs (matches the server's mdm_usage_samples). */
    private fun label(uid: Int): String = when (uid) {
        NetworkStats.Bucket.UID_TETHERING -> "__tethering__"
        NetworkStats.Bucket.UID_REMOVED -> "__removed__"
        else -> context.packageManager.getPackagesForUid(uid)?.singleOrNull()
            ?: context.packageManager.getNameForUid(uid) // e.g. "android.uid.system:1000" for shared UIDs
            ?: "__uid_${uid}__"
    }.take(255)

    private companion object {
        const val KEY_LAST_HOUR = "last_hour"
        const val MAX_BACKLOG_HOURS = 48L

        @Suppress("DEPRECATION")
        val NETWORKS = listOf("mobile" to ConnectivityManager.TYPE_MOBILE, "wifi" to ConnectivityManager.TYPE_WIFI)
    }
}
