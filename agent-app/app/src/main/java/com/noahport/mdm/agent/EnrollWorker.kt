package com.noahport.mdm.agent

import android.content.Context
import android.os.Build
import androidx.work.Worker
import androidx.work.WorkerParameters
import org.json.JSONObject

/** Registers the phone with the server using the enrollment code handed over by NoahportEnroll.exe. */
class EnrollWorker(context: Context, params: WorkerParameters) : Worker(context, params) {

    override fun doWork(): Result {
        val ctx = applicationContext
        val enrollment = EnrollmentTokenReceiver.prefs(ctx)
        val server = enrollment.getString(EnrollmentTokenReceiver.EXTRA_SERVER, null)
        val code = SecureStore.getString(ctx, EnrollmentTokenReceiver.SECRET_ENROLLMENT_CODE)
        if (server == null || code == null) return Result.failure()

        val info = DeviceInfo(ctx)
        val body = JSONObject()
            .put("enrollment_token", code)
            .put("asset_tag", enrollment.getString(EnrollmentTokenReceiver.EXTRA_ASSET_TAG, ""))
            .put("serial", info.serial)
            .put("manufacturer", Build.MANUFACTURER)
            .put("model", Build.MODEL)
            .put("android_version", Build.VERSION.RELEASE)
            .put("security_patch", Build.VERSION.SECURITY_PATCH)
            .put("agent_version_code", info.versionCode)
            .put("agent_version_name", info.versionName)

        val response = try {
            ApiClient(server).post("enroll", body)
        } catch (e: Exception) {
            Agent.prefs(ctx).edit().putString(Agent.KEY_LAST_ERROR, "enroll: $e").apply()
            return Result.retry()
        }
        if (response.code != 201 || response.body == null) {
            val message = response.body?.optString("message") ?: "HTTP ${response.code}"
            SpikeLog.log(ctx, "FAIL enroll: $message")
            Agent.prefs(ctx).edit().putString(Agent.KEY_LAST_ERROR, "enroll: $message").apply()
            // A refused code won't start working by retrying; network/server errors might.
            return if (response.code in 400..499) Result.failure() else Result.retry()
        }

        SecureStore.putString(ctx, Agent.SECRET_DEVICE_TOKEN, response.body.getString("device_token"))
        SecureStore.remove(ctx, EnrollmentTokenReceiver.SECRET_ENROLLMENT_CODE) // single purpose, not needed again
        Agent.prefs(ctx).edit()
            .putString(Agent.KEY_SERVER, server)
            .putString(Agent.KEY_DEVICE_UUID, response.body.getString("device_uuid"))
            .remove(Agent.KEY_LAST_ERROR)
            .apply()
        SpikeLog.log(ctx, "PASS enrolled with $server as ${response.body.getString("device_uuid")}")

        CommandRunner.provisionResetToken(ctx)
        Agent.schedulePeriodicCheckin(ctx)
        AgentService.ensureRunning(ctx)
        Agent.checkInNow(ctx)
        return Result.success()
    }
}
