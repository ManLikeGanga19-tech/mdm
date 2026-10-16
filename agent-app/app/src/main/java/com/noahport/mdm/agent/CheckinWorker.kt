package com.noahport.mdm.agent

import android.content.Context
import android.os.Build
import androidx.work.Worker
import androidx.work.WorkerParameters
import org.json.JSONObject
import java.time.Instant

/**
 * Check-in: report status, receive the effective policy and pending commands, apply/run them, report results.
 * Runs every ~15 minutes (Agent.schedulePeriodicCheckin) and on demand (Agent.checkInNow).
 */
class CheckinWorker(context: Context, params: WorkerParameters) : Worker(context, params) {

    override fun doWork(): Result {
        val ctx = applicationContext
        val prefs = Agent.prefs(ctx)
        val server = prefs.getString(Agent.KEY_SERVER, null) ?: return Result.success() // not enrolled
        val token = SecureStore.getString(ctx, Agent.SECRET_DEVICE_TOKEN) ?: return Result.success()
        val api = ApiClient(server, token)
        AgentService.ensureRunning(ctx) // restarts it if Android ever stopped it
        val enforcer = PolicyEnforcer(ctx)

        val response = try {
            api.post("checkin", status(ctx, enforcer.appliedVersion))
        } catch (e: Exception) {
            prefs.edit().putString(Agent.KEY_LAST_ERROR, "check-in: $e").apply()
            return Result.retry()
        }
        if (response.code == 401) {
            // Credential revoked (phone retired or re-enrolled elsewhere): stop until enrolled again.
            SpikeLog.log(ctx, "FAIL check-in refused (401): credential revoked")
            prefs.edit().putString(Agent.KEY_LAST_ERROR, "check-in refused: credential revoked").apply()
            return Result.failure()
        }
        val body = response.body
        if (response.code != 200 || body == null) {
            prefs.edit().putString(Agent.KEY_LAST_ERROR, "check-in: HTTP ${response.code}").apply()
            return Result.retry()
        }
        prefs.edit().putString(Agent.KEY_LAST_CHECKIN, Instant.now().toString()).remove(Agent.KEY_LAST_ERROR).apply()
        // Asset tag and current holder for the notice: the clerk is attached at the shift handover, not at enrollment.
        EnrollmentTokenReceiver.prefs(ctx).edit()
            .apply { body.optString("asset_tag").takeIf { it.isNotEmpty() }?.let { putString(EnrollmentTokenReceiver.EXTRA_ASSET_TAG, it) } }
            .putString(EnrollmentTokenReceiver.KEY_HOLDER, if (body.isNull("holder")) "" else body.optString("holder"))
            .apply()

        val before = enforcer.appliedVersion
        val applied = enforcer.apply(body.getJSONObject("policy"))

        val runner = CommandRunner(ctx) { uuid, ok, message ->
            runCatching {
                api.post("commands/$uuid/result", JSONObject().put("status", if (ok) "succeeded" else "failed").put("message", message))
            }.onFailure { SpikeLog.log(ctx, "FAIL reporting command $uuid: $it") }
        }
        val commands = body.optJSONArray("commands")
        for (i in 0 until (commands?.length() ?: 0)) {
            val command = commands!!.getJSONObject(i)
            runner.run(command.getString("uuid"), command.getString("type"), command.optJSONObject("payload"))
        }

        // Tell the server straight away that new rules are in force, instead of at the next 15-minute check-in.
        if (applied && enforcer.appliedVersion != before) {
            runCatching { api.post("checkin", status(ctx, enforcer.appliedVersion)) }
        }

        // Telemetry (Milestone B): queue new hourly usage and app-list changes, then upload everything waiting.
        // Commands come first above, so a slow upload never delays them.
        runCatching { UsageCollector(ctx).collect() }.onFailure { SpikeLog.log(ctx, "FAIL usage collection: $it") }
        runCatching { InventoryCollector(ctx).collectIfChanged() }.onFailure { SpikeLog.log(ctx, "FAIL inventory: $it") }
        Outbox.flush(ctx, api)
        return Result.success()
    }

    private fun status(ctx: Context, appliedVersion: Int): JSONObject {
        val info = DeviceInfo(ctx)
        return JSONObject()
            .put("battery_percent", info.batteryPercent)
            .put("battery_charging", info.batteryCharging)
            .put("network_type", info.networkType)
            .put("android_version", Build.VERSION.RELEASE)
            .put("security_patch", Build.VERSION.SECURITY_PATCH)
            .put("agent_version_code", info.versionCode)
            .put("agent_version_name", info.versionName)
            .apply { info.serial?.let { put("serial", it) } }
            .apply { info.imei?.let { put("imei", it) } }
            .apply { if (appliedVersion >= 0) put("applied_policy_version", appliedVersion) }
            .apply { PushReceiver.currentToken(ctx)?.let { put("fcm_token", it) } }
            .apply {
                EnrollmentTokenReceiver.prefs(ctx).getString(NoticeView.KEY_ACKNOWLEDGED_AT, null)
                    ?.let { put("notice_acknowledged_at", it) }
            }
    }
}
