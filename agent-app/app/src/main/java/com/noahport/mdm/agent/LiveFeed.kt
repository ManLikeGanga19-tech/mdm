package com.noahport.mdm.agent

import org.json.JSONArray
import org.json.JSONObject
import java.time.Instant

/**
 * Event lines for the live console on the phone's admin page: while live view runs, every agent log line
 * (SpikeLog: commands, rules applied, handover lock, real-time channel) and the service's state changes (network,
 * charger, battery) are queued here and sent with the next live sample. Nothing is kept when live view is off.
 */
object LiveFeed {
    private const val MAX_QUEUED = 100

    @Volatile
    var active = false

    private val queue = ArrayDeque<JSONObject>()

    fun add(text: String) {
        if (!active) return
        synchronized(queue) {
            queue.addLast(JSONObject().put("at", Instant.now().toString()).put("text", text.take(300)))
            if (queue.size > MAX_QUEUED) queue.removeFirst()
        }
    }

    /** Everything queued since the last call. */
    fun drain(): JSONArray = synchronized(queue) {
        JSONArray().also { out -> while (queue.isNotEmpty()) out.put(queue.removeFirst()) }
    }

    fun clear() = synchronized(queue) { queue.clear() }
}
