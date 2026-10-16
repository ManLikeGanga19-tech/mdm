package com.noahport.mdm.agent

import android.app.admin.DevicePolicyManager
import android.content.Context
import android.content.Intent

/**
 * Handover lock (server rule handover_lock): the phone was not handed over in the last handover window. While locked,
 * HandoverLockActivity is pinned with lock task mode (device owner only), so the phone can't be used even by someone
 * who knows the PIN; the power menu stays available for emergency calls and restarts. A recorded handover lifts it.
 */
object HandoverLock {
    private const val PREFS = "handover_lock"
    private const val KEY_LOCKED = "locked"

    fun isLocked(context: Context) = prefs(context).getBoolean(KEY_LOCKED, false)

    fun prefs(context: Context) = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    fun apply(context: Context, locked: Boolean) {
        val dpm = context.getSystemService(DevicePolicyManager::class.java)
        val admin = AdminReceiver.component(context)
        val changed = locked != isLocked(context)
        prefs(context).edit().putBoolean(KEY_LOCKED, locked).apply() // HandoverLockActivity watches this

        if (locked) {
            dpm.setLockTaskPackages(admin, arrayOf(context.packageName))
            dpm.setLockTaskFeatures(admin, DevicePolicyManager.LOCK_TASK_FEATURE_GLOBAL_ACTIONS)
            dpm.setDeviceOwnerLockScreenInfo(admin, context.getString(R.string.handover_lock_message))
            if (changed) {
                show(context)
                SpikeLog.log(context, "INFO handover lock ON")
            }
        } else {
            dpm.setDeviceOwnerLockScreenInfo(admin, null)
            if (changed) SpikeLog.log(context, "INFO handover lock OFF")
        }
    }

    /** Brings the lock screen to the front (after a policy change, a reboot, or if the clerk left it somehow). */
    fun show(context: Context) {
        if (!isLocked(context)) return
        runCatching {
            context.startActivity(
                Intent(context, HandoverLockActivity::class.java)
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP),
            )
        }.onFailure { SpikeLog.log(context, "FAIL showing handover lock: $it") }
    }
}
