package com.noahport.mdm.agent

import android.content.Context
import androidx.work.Worker
import androidx.work.WorkerParameters

/**
 * The scheduled side of Checkin: every ~15 minutes (Agent.schedulePeriodicCheckin), and the retry when a check-in
 * started right away (Checkin.runSoon) could not reach the server.
 */
class CheckinWorker(context: Context, params: WorkerParameters) : Worker(context, params) {

    override fun doWork(): Result = when (Checkin.run(applicationContext)) {
        Checkin.Outcome.DONE -> Result.success()
        Checkin.Outcome.RETRY -> Result.retry()
        Checkin.Outcome.STOP -> Result.failure()
    }
}
