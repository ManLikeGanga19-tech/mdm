package com.noahport.mdm.agent

import android.content.Context
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import java.util.concurrent.TimeUnit

/** Enrollment state and work scheduling shared by the receiver, the workers and the screens. */
object Agent {
    private const val PREFS = "agent"
    const val KEY_SERVER = "server_url"
    const val KEY_DEVICE_UUID = "device_uuid"
    const val KEY_LAST_CHECKIN = "last_checkin_at"
    const val KEY_LAST_ERROR = "last_error"
    const val SECRET_DEVICE_TOKEN = "device_token"

    private const val WORK_ENROLL = "enroll"
    private const val WORK_CHECKIN_NOW = "checkin-now"
    private const val WORK_CHECKIN_PERIODIC = "checkin"

    fun prefs(context: Context) = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    fun isEnrolled(context: Context) = prefs(context).contains(KEY_DEVICE_UUID)

    private val online = Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()

    /** Enroll as soon as there is a network; retried with backoff until it succeeds. */
    fun enrollSoon(context: Context) {
        WorkManager.getInstance(context).enqueueUniqueWork(
            WORK_ENROLL,
            ExistingWorkPolicy.REPLACE,
            OneTimeWorkRequestBuilder<EnrollWorker>()
                .setConstraints(online)
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
                .build(),
        )
    }

    /**
     * One check-in right now (after enrolling or applying new rules, when the app is opened, on a wake-up from the
     * server). Runs on the agent's own thread; WorkManager only takes over if it can't reach the server.
     */
    fun checkInNow(context: Context) = Checkin.runSoon(context)

    private val recentWakeIds = ArrayDeque<String>()

    /**
     * A "check in now" from the server. The same wake-up arrives over the real-time channel and Firebase (same [id]);
     * whichever comes first triggers the check-in and the other is ignored.
     */
    fun wake(context: Context, id: String?) {
        if (id != null) {
            synchronized(recentWakeIds) {
                if (id in recentWakeIds) return
                recentWakeIds.addLast(id)
                if (recentWakeIds.size > 20) recentWakeIds.removeFirst()
            }
        }
        checkInNow(context)
    }

    /** WorkManager fallback for a check-in that failed (no network, server down): retried with backoff. */
    fun scheduleCheckinRetry(context: Context) {
        val request = OneTimeWorkRequestBuilder<CheckinWorker>()
            .setConstraints(online)
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
            .build()
        WorkManager.getInstance(context).enqueueUniqueWork(WORK_CHECKIN_NOW, ExistingWorkPolicy.REPLACE, request)
    }

    /** The regular ~15-minute check-in (WorkManager's minimum). Survives reboots. */
    fun schedulePeriodicCheckin(context: Context) {
        WorkManager.getInstance(context).enqueueUniquePeriodicWork(
            WORK_CHECKIN_PERIODIC,
            ExistingPeriodicWorkPolicy.KEEP,
            PeriodicWorkRequestBuilder<CheckinWorker>(15, TimeUnit.MINUTES).setConstraints(online).build(),
        )
    }
}
