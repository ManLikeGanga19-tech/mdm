package com.noahport.mdm.agent

import android.app.admin.DevicePolicyManager
import android.content.Context
import android.os.Build
import org.json.JSONObject
import java.security.SecureRandom

/**
 * Runs remote commands from the admin site. Reboot and wipe end the agent's process, so their result is reported
 * through [report] *before* the action (best effort); everything else reports after it.
 */
class CommandRunner(private val context: Context, private val report: (uuid: String, ok: Boolean, message: String?) -> Unit) {

    private val dpm = context.getSystemService(DevicePolicyManager::class.java)
    private val admin = AdminReceiver.component(context)
    private val done = context.getSharedPreferences("commands", Context.MODE_PRIVATE)

    fun run(uuid: String, type: String, payload: JSONObject? = null) {
        if (done.contains(uuid)) { // already ran; the server just hasn't recorded the result yet
            report(uuid, done.getBoolean(uuid, false), null)
            return
        }
        SpikeLog.log(context, "INFO command $type ($uuid)")
        val outcome = runCatching {
            when (type) {
                "lock" -> dpm.lockNow()
                "reset_password" -> resetPassword()
                "reboot" -> finishFirst(uuid) { dpm.reboot(admin) }
                "wipe" -> finishFirst(uuid) { wipe() }
                "live_view" -> AgentService.startLive(context, payload?.optInt("seconds", 600) ?: 600)
                else -> error("unknown command type $type")
            }
        }
        remember(uuid, outcome.isSuccess)
        report(uuid, outcome.isSuccess, outcome.exceptionOrNull()?.let { "${it.javaClass.simpleName}: ${it.message}" })
    }

    private fun finishFirst(uuid: String, action: () -> Unit) {
        remember(uuid, true)
        report(uuid, true, null)
        action()
    }

    private fun remember(uuid: String, ok: Boolean) {
        done.edit().putBoolean(uuid, ok).apply()
    }

    /** Clears the screen lock (the clerk sets a new one). Needs the token provisioned at enrollment to be active. */
    private fun resetPassword() {
        val token = SecureStore.get(context, SECRET_RESET_TOKEN) ?: error("no reset token provisioned")
        check(dpm.isResetPasswordTokenActive(admin)) { "reset token not active yet: the phone must be unlocked once after enrollment" }
        check(dpm.resetPasswordWithToken(admin, null, token, 0)) { "resetPasswordWithToken returned false" }
    }

    /** Android 14+ refuses wipeData() from the device owner on the system user; wipeDevice() replaces it. */
    private fun wipe() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            dpm.wipeDevice(0)
        } else {
            @Suppress("DEPRECATION")
            dpm.wipeData(0)
        }
    }

    companion object {
        const val SECRET_RESET_TOKEN = "reset_password_token"

        /**
         * Provisions the reset-password token (32 random bytes, stored encrypted). It is active immediately on a
         * phone without a screen lock, otherwise after the next unlock (Android's rule).
         */
        fun provisionResetToken(context: Context) {
            val dpm = context.getSystemService(DevicePolicyManager::class.java)
            val token = ByteArray(32).also { SecureRandom().nextBytes(it) }
            if (dpm.setResetPasswordToken(AdminReceiver.component(context), token)) {
                SecureStore.put(context, SECRET_RESET_TOKEN, token)
                SpikeLog.log(context, "PASS reset-password token provisioned")
            } else {
                SpikeLog.log(context, "FAIL setResetPasswordToken returned false")
            }
        }
    }
}
