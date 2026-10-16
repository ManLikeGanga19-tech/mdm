package com.noahport.mdm.agent

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
 * type, signal strength and the network's bandwidth estimate, posted to the server in small batches. Runs on its own
 * thread inside AgentService until the time limit or until the server answers continue=false.
 */
class LiveSampler(private val context: Context, private val onStopped: () -> Unit) {

    private val thread = HandlerThread("live").apply { start() }
    private val handler = Handler(thread.looper)
    private val batch = JSONArray()
    private var lastRx = -1L
    private var lastTx = -1L
    private var lastAt = 0L
    private var endAt = 0L

    @Volatile
    var running = false
        private set

    fun start(seconds: Int) {
        handler.post {
            endAt = SystemClock.elapsedRealtime() + seconds * 1000L
            if (!running) {
                running = true
                lastRx = -1
                handler.post(tick)
            }
        }
    }

    fun stop() {
        handler.post {
            if (running) {
                running = false
                handler.removeCallbacks(tick)
                onStopped()
            }
        }
    }

    fun shutdown() {
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
            if (batch.length() >= 2 && !flush()) return // flush() stops us when the server says so
            handler.postDelayed(this, INTERVAL_MS)
        }
    }

    private fun sample() {
        val now = SystemClock.elapsedRealtime()
        val rx = TrafficStats.getTotalRxBytes()
        val tx = TrafficStats.getTotalTxBytes()
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
                    },
            )
        }
        lastRx = rx
        lastTx = tx
        lastAt = now
    }

    /** Posts the batch; returns false (and stops) when the server ends the session. */
    private fun flush(): Boolean {
        if (batch.length() == 0) return true
        val server = Agent.prefs(context).getString(Agent.KEY_SERVER, null) ?: return true
        val token = SecureStore.getString(context, Agent.SECRET_DEVICE_TOKEN) ?: return true
        val samples = JSONArray(batch.toString())
        while (batch.length() > 0) batch.remove(0)
        val keepGoing = runCatching {
            ApiClient(server, token).post("live", JSONObject().put("samples", samples)).body?.optBoolean("continue", true) ?: true
        }.getOrDefault(true) // a dropped request shouldn't end the session
        if (!keepGoing) stop()
        return keepGoing
    }

    private companion object {
        const val INTERVAL_MS = 2000L
    }
}
