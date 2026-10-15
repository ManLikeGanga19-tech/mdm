package com.noahport.mdm.agent

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/** Restarts the always-on agent service after a reboot or an agent update, and reports in. */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == Intent.ACTION_BOOT_COMPLETED || intent.action == Intent.ACTION_MY_PACKAGE_REPLACED) {
            AgentService.ensureRunning(context)
            if (Agent.isEnrolled(context)) Agent.checkInNow(context)
        }
    }
}
