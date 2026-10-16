package com.noahport.mdm.agent

import android.content.Context
import org.json.JSONObject
import java.io.IOException
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder

/**
 * Real-time channel: while AgentService runs, the phone keeps one server-sent-events connection open to the server's
 * Mercure hub (built into FrankenPHP) and listens on its own topic, so a "check in now" arrives within a second of an
 * admin's action, without Firebase or Android's job scheduler. Hub, topic and a subscribe-only token come with every
 * check-in. Firebase stays as the second channel for when this connection is down (Doze, a network change); both
 * carry the same wake-up id and Agent.wake acts once.
 */
object RealtimeChannel {
    private const val PREFS = "realtime"
    private const val KEY_HUB = "hub_url"
    private const val KEY_TOPIC = "topic"
    private const val KEY_TOKEN = "token"

    /** The hub sends a comment line every 40 s; no byte for this long means the connection is dead. */
    private const val READ_TIMEOUT_MS = 100_000
    private const val CONNECT_TIMEOUT_MS = 15_000
    private const val MAX_BACKOFF_MS = 60_000L

    /** After a gap longer than this, a wake-up may have been missed: check in once on reconnecting. */
    private const val CATCH_UP_AFTER_MS = 20_000L

    private val lock = Object()
    private var thread: Thread? = null

    @Volatile private var connection: HttpURLConnection? = null

    private fun prefs(context: Context) = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    /** Stores the check-in's "realtime" object (null: switched off). Reconnects if the hub or topic changed. */
    fun configure(context: Context, realtime: JSONObject?) {
        val prefs = prefs(context)
        val hub = realtime?.optString("hub_url").orEmpty()
        val topic = realtime?.optString("topic").orEmpty()
        val changed = hub != prefs.getString(KEY_HUB, "") || topic != prefs.getString(KEY_TOPIC, "")
        prefs.edit()
            .putString(KEY_HUB, hub)
            .putString(KEY_TOPIC, topic)
            .putString(KEY_TOKEN, realtime?.optString("token").orEmpty())
            .apply()
        if (changed) reconnect()
    }

    /** Starts the listening thread (AgentService.onCreate). */
    fun start(context: Context) {
        synchronized(lock) {
            if (thread?.isAlive == true) return
            thread = Thread({ loop(context.applicationContext) }, "realtime").apply { isDaemon = true; start() }
        }
    }

    /** Stops it (AgentService.onDestroy). */
    fun stop() {
        val t = synchronized(lock) { thread.also { thread = null } }
        t?.interrupt()
        connection?.disconnect()
    }

    /** Drops the current connection so the thread connects again now (network changed, new hub or topic). */
    fun reconnect() {
        connection?.disconnect()
        synchronized(lock) { lock.notifyAll() }
    }

    private fun loop(ctx: Context) {
        var backoff = 2_000L
        var downSince = 0L // 0: never connected yet in this run (the service's start comes with a check-in anyway)
        while (!Thread.currentThread().isInterrupted) {
            val prefs = prefs(ctx)
            val hub = prefs.getString(KEY_HUB, "").orEmpty()
            val topic = prefs.getString(KEY_TOPIC, "").orEmpty()
            val token = prefs.getString(KEY_TOKEN, "").orEmpty()
            if (hub.isEmpty() || topic.isEmpty() || !Agent.isEnrolled(ctx)) {
                if (!pause(MAX_BACKOFF_MS)) return // switched off or not enrolled yet: wait for configure()
                continue
            }

            var wasConnected = false
            try {
                val conn = URL(hub + (if ('?' in hub) "&" else "?") + "match=" + URLEncoder.encode(topic, "UTF-8"))
                    .openConnection() as HttpURLConnection
                connection = conn
                conn.connectTimeout = CONNECT_TIMEOUT_MS
                conn.readTimeout = READ_TIMEOUT_MS
                conn.setRequestProperty("Authorization", "Bearer $token")
                conn.setRequestProperty("Accept", "text/event-stream")
                conn.setRequestProperty("Cache-Control", "no-cache")
                when (val code = conn.responseCode) {
                    200 -> {
                        wasConnected = true
                        backoff = 2_000L
                        SpikeLog.log(ctx, "INFO real-time channel connected")
                        if (downSince != 0L && System.currentTimeMillis() - downSince > CATCH_UP_AFTER_MS) Agent.checkInNow(ctx)
                        read(ctx, conn)
                    }
                    401, 403 -> {
                        // Token expired or the server's keys changed: a check-in brings a fresh one.
                        SpikeLog.log(ctx, "FAIL real-time channel refused ($code): renewing the token")
                        Agent.checkInNow(ctx)
                        backoff = MAX_BACKOFF_MS
                    }
                    else -> SpikeLog.log(ctx, "FAIL real-time channel: HTTP $code")
                }
            } catch (e: IOException) {
                // Network change, read timeout, server restart, or reconnect(): connect again below.
                if (wasConnected) LiveFeed.add("Real-time channel dropped (${e.javaClass.simpleName}), reconnecting")
            } catch (e: Exception) {
                SpikeLog.log(ctx, "FAIL real-time channel: $e")
            } finally {
                connection?.disconnect()
                connection = null
            }
            if (wasConnected) downSince = System.currentTimeMillis()
            if (!pause(if (wasConnected) 1_000L else backoff)) return
            if (!wasConnected) backoff = (backoff * 2).coerceAtMost(MAX_BACKOFF_MS)
        }
    }

    /** Server-sent events: "data:" lines up to a blank line make one event; lines starting with ':' are heartbeats. */
    private fun read(ctx: Context, conn: HttpURLConnection) {
        val data = StringBuilder()
        conn.inputStream.bufferedReader().use { reader ->
            while (true) {
                val line = reader.readLine() ?: return // the hub closed the stream
                when {
                    line.isEmpty() -> {
                        if (data.isNotEmpty()) handle(ctx, data.toString())
                        data.clear()
                    }
                    line.startsWith("data:") -> data.append(line.removePrefix("data:").trimStart())
                }
            }
        }
    }

    private fun handle(ctx: Context, data: String) {
        val event = runCatching { JSONObject(data) }.getOrNull() ?: return
        if (event.optString("type") == "checkin") Agent.wake(ctx, event.optString("id").takeIf { it.isNotEmpty() })
    }

    /** Sleeps up to [ms], or until reconnect()/configure() wakes it. False if the thread was stopped. */
    private fun pause(ms: Long): Boolean = try {
        synchronized(lock) { lock.wait(ms) }
        !Thread.currentThread().isInterrupted
    } catch (e: InterruptedException) {
        false
    }
}
