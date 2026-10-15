package com.noahport.mdm.agent

import android.app.admin.DevicePolicyManager
import android.content.Context
import android.os.UserManager
import org.json.JSONObject

/**
 * The only place policy is applied on the phone (CLAUDE.md rule 5). Input is the effective policy from the server's
 * PolicyBuilder: {"version": n, "hotspot": "blocked"|"allowed", "blocked_apps": [...], "network_logging": bool}.
 */
class PolicyEnforcer(private val context: Context) {

    private val dpm = context.getSystemService(DevicePolicyManager::class.java)
    private val admin = AdminReceiver.component(context)
    private val prefs = context.getSharedPreferences("policy", Context.MODE_PRIVATE)

    /** Last policy version applied without errors, reported at check-in; -1 if none. */
    val appliedVersion: Int get() = prefs.getInt(KEY_APPLIED_VERSION, -1)

    /** Applies the policy if its version is new. Returns true when the phone now matches it. */
    fun apply(policy: JSONObject): Boolean {
        val version = policy.getInt("version")
        if (version == appliedVersion) return true
        if (!dpm.isDeviceOwnerApp(context.packageName)) {
            SpikeLog.log(context, "FAIL policy: agent is not device owner")
            return false
        }

        val errors = mutableListOf<String>()
        runCatching { applyHotspot(policy.optString("hotspot", "allowed") == "blocked") }
            .onFailure { errors += "hotspot: $it" }
        runCatching { applyBlockedApps(policy.optJSONArray("blocked_apps")?.let { a -> List(a.length()) { a.getString(it) } }.orEmpty()) }
            .onFailure { errors += "apps: $it" }
        runCatching { dpm.setNetworkLoggingEnabled(admin, policy.optBoolean("network_logging", true)) }
            .onFailure { errors += "network logging: $it" }

        if (errors.isNotEmpty()) {
            SpikeLog.log(context, "FAIL policy v$version: ${errors.joinToString("; ")}")
            return false
        }
        prefs.edit().putInt(KEY_APPLIED_VERSION, version).apply()
        SpikeLog.log(context, "PASS policy v$version applied")
        return true
    }

    private fun applyHotspot(blocked: Boolean) {
        if (blocked) {
            dpm.addUserRestriction(admin, UserManager.DISALLOW_CONFIG_TETHERING)
        } else {
            dpm.clearUserRestriction(admin, UserManager.DISALLOW_CONFIG_TETHERING)
        }
    }

    /** Suspends listed apps and unsuspends ones this agent suspended earlier that are no longer listed. */
    private fun applyBlockedApps(wanted: List<String>) {
        val target = wanted.filter { it != context.packageName }.toSet()
        val previous = prefs.getStringSet(KEY_SUSPENDED, emptySet()).orEmpty()

        val toRelease = (previous - target).toTypedArray()
        if (toRelease.isNotEmpty()) dpm.setPackagesSuspended(admin, toRelease, false)

        // Apps that aren't installed are reported back as "not suspended"; they are kept in the list so they are
        // blocked as soon as someone installs them (re-applied at the next policy change or re-enrollment).
        val notInstalled = if (target.isNotEmpty()) dpm.setPackagesSuspended(admin, target.toTypedArray(), true).toSet() else emptySet()
        if (notInstalled.isNotEmpty()) SpikeLog.log(context, "INFO not installed, not suspended: ${notInstalled.joinToString()}")

        prefs.edit().putStringSet(KEY_SUSPENDED, target).apply()
    }

    private companion object {
        const val KEY_APPLIED_VERSION = "applied_version"
        const val KEY_SUSPENDED = "suspended_packages"
    }
}
