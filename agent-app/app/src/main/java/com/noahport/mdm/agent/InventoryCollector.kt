package com.noahport.mdm.agent

import android.content.Context
import android.content.pm.ApplicationInfo
import android.content.pm.PackageManager
import org.json.JSONArray
import org.json.JSONObject

/**
 * Installed apps (name, package, version) for the admin site: shows app names in usage reports and lets IT pick
 * apps to block. Includes user-installed apps and system apps that appear in the launcher. Uploaded when the list
 * changes, and at least once a day.
 */
class InventoryCollector(private val context: Context) {

    private val prefs = context.getSharedPreferences("inventory", Context.MODE_PRIVATE)

    fun collectIfChanged() {
        val pm = context.packageManager
        val apps = pm.getInstalledApplications(PackageManager.GET_META_DATA)
            .filter { info ->
                val system = info.flags and ApplicationInfo.FLAG_SYSTEM != 0
                !system || pm.getLaunchIntentForPackage(info.packageName) != null
            }
            .sortedBy { it.packageName }
            .map { info ->
                val pkg = runCatching { pm.getPackageInfo(info.packageName, 0) }.getOrNull()
                JSONObject()
                    .put("package", info.packageName)
                    .put("label", info.loadLabel(pm).toString().take(255))
                    .put("version_name", pkg?.versionName?.take(100))
                    .put("version_code", pkg?.longVersionCode ?: 0)
                    .put("system", info.flags and ApplicationInfo.FLAG_SYSTEM != 0)
            }

        val fingerprint = apps.joinToString("|") { "${it.getString("package")}:${it.optLong("version_code")}" }.hashCode()
        val stale = System.currentTimeMillis() - prefs.getLong(KEY_SENT_AT, 0) > DAY_MS
        if (!stale && fingerprint == prefs.getInt(KEY_FINGERPRINT, 0)) return

        Outbox.add(context, "inventory", JSONObject().put("apps", JSONArray(apps)))
        prefs.edit().putInt(KEY_FINGERPRINT, fingerprint).putLong(KEY_SENT_AT, System.currentTimeMillis()).apply()
    }

    private companion object {
        const val KEY_FINGERPRINT = "fingerprint"
        const val KEY_SENT_AT = "sent_at"
        const val DAY_MS = 24 * 60 * 60 * 1000L
    }
}
