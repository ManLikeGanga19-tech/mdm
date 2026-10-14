package com.noahport.mdm.agent

import android.content.Context
import android.util.Log
import java.io.File
import java.time.Instant

/** Spike results go to logcat and to files/spike.log so they survive restarts and can be pulled with adb. */
object SpikeLog {
    private const val TAG = "NoahportSpike"
    private const val FILE = "spike.log"

    @Volatile
    var listener: ((String) -> Unit)? = null

    @Synchronized
    fun log(context: Context, message: String) {
        val line = "${Instant.now()} $message" // Instant prints UTC
        Log.i(TAG, message)
        File(context.filesDir, FILE).appendText(line + "\n")
        listener?.invoke(line)
    }

    @Synchronized
    fun read(context: Context): String =
        File(context.filesDir, FILE).takeIf { it.exists() }?.readText().orEmpty()

    @Synchronized
    fun clear(context: Context) {
        File(context.filesDir, FILE).delete()
    }
}
