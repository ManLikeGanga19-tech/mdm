package com.noahport.mdm.agent

import android.app.usage.NetworkStats
import android.app.usage.NetworkStatsManager
import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.net.TrafficStats
import android.os.Handler
import android.os.HandlerThread
import android.os.SystemClock
import org.json.JSONArray
import org.json.JSONObject
import java.time.Instant

/**
 * Live view: every 2 seconds, the phone's current download/upload speed (device-wide TrafficStats counters), network
 * type, signal strength, the network's bandwidth estimate and the apps using the most data right now, posted to the
 * server with the agent's event lines (LiveFeed). Runs on its own thread inside AgentService until the time limit or
 * until the server answers continue=false.
 */
class LiveSampler(private val context: Context, private val onStopped: () -> Unit) {

    private val thread = HandlerThread("live").apply { start() }
    private val handler = Handler(thread.looper)
    private val batch = JSONArray()
    private var lastRx = -1L
    private var lastTx = -1L
    private var lastAt = 0L
    private var endAt = 0L

    // Per-app speeds (see sampleApps).
    private var appsWindow = 0L to 0L
    private var lastAppTotals: Map<Int, LongArray>? = null
    private var lastAppChangeAt = 0L // when the per-app counters last moved (0: not yet)
    private var lastAppBase = 0L // when the first counters were read
    private var topApps = JSONArray()
    private val labels = HashMap<Int, Pair<String, String>>()

    @Volatile
    var running = false
        private set

    fun start(seconds: Int) {
        handler.post {
            endAt = SystemClock.elapsedRealtime() + seconds * 1000L
            if (!running) {
                running = true
                lastRx = -1
                lastAppTotals = null
                topApps = JSONArray()
                // A fixed window around the session: it holds the whole of Android's current ~2-hour bucket, so
                // differences between two queries are exactly the traffic in between (see sampleApps).
                val now = System.currentTimeMillis()
                appsWindow = (now - 4 * HOUR_MS) to (now + 24 * HOUR_MS)
                LiveFeed.clear()
                LiveFeed.active = true
                handler.post(tick)
            }
        }
    }

    fun stop() {
        handler.post {
            if (running) {
                running = false
                LiveFeed.active = false
                handler.removeCallbacks(tick)
                onStopped()
            }
        }
    }

    fun shutdown() {
        LiveFeed.active = false
        handler.removeCallbacksAndMessages(null)
        thread.quitSafely()
    }

    private val tick = object : Runnable {
        override fun run() {
            if (!running) return
            if (SystemClock.elapsedRealtime() > endAt) {
                flush()
                stop()
                return
            }
            sample()
            if (!flush()) return // flush() stops us when the server says so
            handler.postDelayed(this, INTERVAL_MS)
        }
    }

    private fun sample() {
        val now = SystemClock.elapsedRealtime()
        val rx = TrafficStats.getTotalRxBytes()
        val tx = TrafficStats.getTotalTxBytes()
        runCatching { sampleApps(now) }.onFailure { SpikeLog.log(context, "FAIL live per-app speeds: $it") }
        if (lastRx >= 0 && now > lastAt) {
            val seconds = (now - lastAt) / 1000.0
            val cm = context.getSystemService(ConnectivityManager::class.java)
            val caps = cm.getNetworkCapabilities(cm.activeNetwork)
            batch.put(
                JSONObject()
                    .put("at", Instant.now().toString())
                    .put("rx_bps", (((rx - lastRx).coerceAtLeast(0)) * 8 / seconds).toLong())
                    .put("tx_bps", (((tx - lastTx).coerceAtLeast(0)) * 8 / seconds).toLong())
                    .put("network", when {
                        caps == null -> "none"
                        caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) -> "wifi"
                        caps.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) -> "mobile"
                        else -> "other"
                    })
                    .apply {
                        caps?.signalStrength?.takeIf { it != NetworkCapabilities.SIGNAL_STRENGTH_UNSPECIFIED }?.let { put("signal_dbm", it) }
                        caps?.linkDownstreamBandwidthKbps?.takeIf { it > 0 }?.let { put("down_kbps", it) }
                        caps?.linkUpstreamBandwidthKbps?.takeIf { it > 0 }?.let { put("up_kbps", it) }
                        if (lastAppChangeAt > 0) {
                            put("apps", topApps)
                            put("apps_age_s", ((now - lastAppChangeAt) / 1000).toInt())
                        }
                    },
            )
        }
        lastRx = rx
        lastTx = tx
        lastAt = now
    }

    /**
     * Apps using the most data. Android keeps per-app counters (NetworkStatsManager; a device owner sees every app)
     * but refreshes them on its own schedule, which can be slower than our 2 s for an ordinary app. So a speed is
     * worked out only when the counters change: the bytes since the previous change over the time since then. The
     * console shows how old the figures are (apps_age_s).
     */
    @Suppress("DEPRECATION") // TYPE_MOBILE / TYPE_WIFI remain the documented inputs to querySummary
    private fun sampleApps(now: Long) {
        val nsm = context.getSystemService(NetworkStatsManager::class.java)
        val totals = HashMap<Int, LongArray>()
        for (type in listOf(ConnectivityManager.TYPE_MOBILE, ConnectivityManager.TYPE_WIFI)) {
            nsm.querySummary(type, null, appsWindow.first, appsWindow.second).use { stats ->
                val bucket = NetworkStats.Bucket()
                while (stats.hasNextBucket()) {
                    stats.getNextBucket(bucket)
                    val t = totals.getOrPut(bucket.uid) { LongArray(2) }
                    t[0] += bucket.rxBytes
                    t[1] += bucket.txBytes
                }
            }
        }
        val previous = lastAppTotals
        if (previous == null) {
            lastAppTotals = totals
            lastAppChangeAt = 0L // nothing to compare with yet
            lastAppBase = now
            return
        }
        val changed = totals.any { (uid, t) -> previous[uid]?.let { it[0] != t[0] || it[1] != t[1] } ?: (t[0] + t[1] > 0) }
        if (!changed) return
        val since = if (lastAppChangeAt > 0) lastAppChangeAt else lastAppBase
        val seconds = ((now - since) / 1000.0).coerceAtLeast(1.0)
        topApps = JSONArray().also { out ->
            totals.mapNotNull { (uid, t) ->
                val p = previous[uid] ?: LongArray(2)
                val rxBps = ((t[0] - p[0]).coerceAtLeast(0) * 8 / seconds).toLong()
                val txBps = ((t[1] - p[1]).coerceAtLeast(0) * 8 / seconds).toLong()
                if (rxBps + txBps > 0) Triple(uid, rxBps, txBps) else null
            }.sortedByDescending { it.second + it.third }.take(TOP_APPS).forEach { (uid, rxBps, txBps) ->
                val (pkg, label) = label(uid)
                out.put(JSONObject().put("package", pkg).put("label", label).put("rx_bps", rxBps).put("tx_bps", txBps))
            }
        }
        lastAppTotals = totals
        lastAppChangeAt = now
    }

    /** Package name and app name for a UID (shared and special UIDs get a readable stand-in). */
    private fun label(uid: Int): Pair<String, String> = labels.getOrPut(uid) {
        val pm = context.packageManager
        when (uid) {
            NetworkStats.Bucket.UID_TETHERING -> "__tethering__" to "Hotspot / tethering"
            NetworkStats.Bucket.UID_REMOVED -> "__removed__" to "Removed apps"
            else -> {
                val pkg = pm.getPackagesForUid(uid)?.singleOrNull()
                val name = pkg?.let { runCatching { pm.getApplicationLabel(pm.getApplicationInfo(it, 0)).toString() }.getOrNull() }
                (pkg ?: pm.getNameForUid(uid) ?: "uid $uid") to (name ?: pkg ?: pm.getNameForUid(uid) ?: "uid $uid")
            }
        }
    }

    /** Posts the batch and queued event lines; returns false (and stops) when the server ends the session. */
    private fun flush(): Boolean {
        val events = LiveFeed.drain()
        if (batch.length() == 0 && events.length() == 0) return true
        val server = Agent.prefs(context).getString(Agent.KEY_SERVER, null) ?: return true
        val token = SecureStore.getString(context, Agent.SECRET_DEVICE_TOKEN) ?: return true
        val samples = JSONArray(batch.toString())
        while (batch.length() > 0) batch.remove(0)
        val keepGoing = runCatching {
            ApiClient(server, token).post("live", JSONObject().put("samples", samples).put("events", events))
                .body?.optBoolean("continue", true) ?: true
        }.getOrDefault(true) // a dropped request shouldn't end the session
        if (!keepGoing) stop()
        return keepGoing
    }

    private companion object {
        const val INTERVAL_MS = 2000L
        const val HOUR_MS = 3_600_000L
        const val TOP_APPS = 5
    }
}
