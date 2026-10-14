package com.noahport.mdm.agent

import android.app.usage.UsageStatsManager
import android.content.Context
import android.os.BatteryManager
import android.os.PowerManager
import androidx.work.Worker
import androidx.work.WorkerParameters
import java.io.File
import java.time.Duration
import java.time.Instant

/** Periodic job for the battery-management soak test: records each run so gaps show up in the report. */
class SoakWorker(context: Context, params: WorkerParameters) : Worker(context, params) {

    override fun doWork(): Result {
        val ctx = applicationContext
        val battery = ctx.getSystemService(BatteryManager::class.java)
            .getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)
        val power = ctx.getSystemService(PowerManager::class.java)
        val bucket = ctx.getSystemService(UsageStatsManager::class.java).appStandbyBucket
        synchronized(SoakWorker::class.java) {
            file(ctx).appendText(
                "${Instant.now()} battery=$battery% idle=${power.isDeviceIdleMode} " +
                    "ignoringBatteryOpt=${power.isIgnoringBatteryOptimizations(ctx.packageName)} bucket=$bucket\n",
            )
        }
        return Result.success()
    }

    companion object {
        const val WORK_NAME = "soak"

        fun file(context: Context) = File(context.filesDir, "soak.log")

        /** Run count, first/last run, and the longest gap between runs (expected ~15 min). */
        fun report(context: Context): String {
            val lines = file(context).takeIf { it.exists() }?.readLines().orEmpty()
            if (lines.isEmpty()) return "Soak: no runs recorded yet"
            val times = lines.map { Instant.parse(it.substringBefore(' ')) }
            val maxGap = times.zipWithNext { a, b -> Duration.between(a, b) }.maxOrNull() ?: Duration.ZERO
            val sinceLast = Duration.between(times.last(), Instant.now())
            return "Soak: ${times.size} runs, first=${times.first()}, last=${times.last()} " +
                "(${sinceLast.toMinutes()} min ago), longest gap=${maxGap.toMinutes()} min\n" +
                "  latest: ${lines.last()}"
        }
    }
}
