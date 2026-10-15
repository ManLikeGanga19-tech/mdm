package com.noahport.mdm.agent

import android.app.Activity
import android.app.AlertDialog
import android.app.admin.DevicePolicyManager
import android.app.usage.NetworkStats
import android.app.usage.NetworkStatsManager
import android.app.usage.UsageStatsManager
import android.content.Intent
import android.content.pm.ApplicationInfo
import android.graphics.Typeface
import android.net.ConnectivityManager
import android.os.Build
import android.os.Bundle
import android.os.PowerManager
import android.os.UserManager
import android.view.View
import android.view.WindowInsets
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import java.net.Inet4Address
import java.net.NetworkInterface
import java.util.concurrent.TimeUnit
import kotlin.concurrent.thread

/**
 * Release builds (staff phones): only the "managed by Noahport" [NoticeView], with no controls, so staff can't
 * change policy from the phone.
 *
 * Debug builds: the Phase 1 spike screen, one button per device-owner capability we need to prove on real hardware.
 * Every result is written to [SpikeLog] as PASS/FAIL/INFO for docs/spike-report.md.
 */
class MainActivity : Activity() {

    private lateinit var dpm: DevicePolicyManager
    private lateinit var logView: TextView
    private lateinit var logScroll: ScrollView
    private lateinit var packageInput: EditText

    private val admin by lazy { AdminReceiver.component(this) }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        dpm = getSystemService(DevicePolicyManager::class.java)
        if (Agent.isEnrolled(this)) {
            Agent.schedulePeriodicCheckin(this) // KEEP: no-op when already scheduled
            AgentService.ensureRunning(this)
        }
        if (!isTestBuild) {
            setContentView(withSystemBarPadding(ScrollView(this).apply { addView(NoticeView(context)) }))
            return
        }
        setContentView(buildUi())
        logView.text = SpikeLog.read(this)
        scrollLogToEnd()
        showStatus()
    }

    override fun onResume() {
        super.onResume()
        // Bringing the app to the front syncs straight away (useful on site, and until push wake-ups exist).
        if (Agent.isEnrolled(this)) Agent.checkInNow(this)
        if (!isTestBuild) return
        SpikeLog.listener = { line ->
            runOnUiThread {
                logView.append(line + "\n")
                scrollLogToEnd()
            }
        }
    }

    override fun onPause() {
        SpikeLog.listener = null
        super.onPause()
    }

    private fun buildUi(): View {
        val controls = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }

        controls.section("Status")
        controls.button("Show status") { showStatus() }
        controls.button("Check in with the server now") {
            Agent.checkInNow(this)
            SpikeLog.log(this, "INFO check-in queued (runs as soon as there is a network)")
        }
        controls.button("Show staff notice (what release builds display)") {
            AlertDialog.Builder(this)
                .setView(ScrollView(this).apply {
                    setPadding(dp(20), dp(16), dp(20), 0)
                    addView(NoticeView(context))
                })
                .setPositiveButton("Close", null)
                .show()
        }

        controls.section("Hotspot (DISALLOW_CONFIG_TETHERING)")
        controls.button("Block hotspot") { setHotspotBlocked(true) }
        controls.button("Unblock hotspot") { setHotspotBlocked(false) }
        controls.button("List active network interfaces") { logInterfaces() }

        controls.section("Data usage (NetworkStatsManager)")
        controls.button("Query last 24 h usage") { thread { queryUsage() } }

        controls.section("Network logging")
        controls.button("Enable network logging") { setNetworkLogging(true) }
        controls.button("Disable network logging") { setNetworkLogging(false) }

        controls.section("Commands")
        controls.button("Lock now") { dpmCall("lockNow") { dpm.lockNow() } }
        controls.button("Reboot") { confirm("Reboot the phone now?") { dpmCall("reboot") { dpm.reboot(admin) } } }

        controls.section("App blocking")
        packageInput = EditText(this).apply {
            setText("com.android.chrome")
            setSingleLine()
        }
        controls.addView(packageInput)
        controls.button("Suspend app") { suspendApp(true) }
        controls.button("Unsuspend app") { suspendApp(false) }
        controls.button("Hide app") { hideApp(true) }
        controls.button("Unhide app") { hideApp(false) }

        controls.section("Battery soak (WorkManager, every 15 min)")
        controls.button("Start soak") { startSoak() }
        controls.button("Soak report") { SpikeLog.log(this, SoakWorker.report(this)) }
        controls.button("Stop soak") {
            WorkManager.getInstance(this).cancelUniqueWork(SoakWorker.WORK_NAME)
            SpikeLog.log(this, "INFO soak stopped")
        }

        controls.section("Housekeeping")
        controls.button("Share log (email, WhatsApp, ...)") { shareLog() }
        controls.button("Clear log") {
            SpikeLog.clear(this)
            logView.text = ""
        }
        controls.button("Release device owner / admin (test phones only)") {
            confirm("Remove device owner? The app loses all control and policies are lifted.") { releaseDeviceOwner() }
        }

        logView = TextView(this).apply {
            typeface = Typeface.MONOSPACE
            textSize = 11f
            setTextIsSelectable(true)
        }
        logScroll = ScrollView(this).apply { addView(logView) }

        return withSystemBarPadding(LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            addView(ScrollView(context).apply { addView(controls) }, LinearLayout.LayoutParams(MATCH, 0, 1f))
            addView(logScroll, LinearLayout.LayoutParams(MATCH, 0, 1f))
        })
    }

    /** targetSdk 35+ is edge-to-edge: keep content clear of the system bars. */
    private fun <T : View> withSystemBarPadding(view: T): T = view.apply {
        val pad = dp(12)
        setPadding(pad, pad, pad, pad)
        setOnApplyWindowInsetsListener { v, insets ->
            val bars = insets.getInsets(WindowInsets.Type.systemBars())
            v.setPadding(pad + bars.left, pad + bars.top, pad + bars.right, pad + bars.bottom)
            insets
        }
    }

    /** Debuggable = a test build with the spike controls; release builds (the permanent key) are for staff phones. */
    private val isTestBuild get() = (applicationInfo.flags and ApplicationInfo.FLAG_DEBUGGABLE) != 0

    private fun showStatus() {
        val power = getSystemService(PowerManager::class.java)
        val bucket = getSystemService(UsageStatsManager::class.java).appStandbyBucket
        val isOwner = dpm.isDeviceOwnerApp(packageName)
        SpikeLog.log(
            this,
            "INFO ${Build.MANUFACTURER} ${Build.MODEL} Android ${Build.VERSION.RELEASE} (SDK ${Build.VERSION.SDK_INT}) " +
                "deviceOwner=$isOwner ignoringBatteryOpt=${power.isIgnoringBatteryOptimizations(packageName)} " +
                "standbyBucket=$bucket",
        )
        if (isOwner) {
            val restrictions = dpm.getUserRestrictions(admin)
            SpikeLog.log(
                this,
                "INFO hotspotBlocked=${restrictions.getBoolean(UserManager.DISALLOW_CONFIG_TETHERING)} " +
                    "networkLogging=${dpm.isNetworkLoggingEnabled(admin)}",
            )
        }
        val enrollment = EnrollmentTokenReceiver.prefs(this)
        SpikeLog.log(
            this,
            "INFO enrollmentToken=${if (enrollment.contains(EnrollmentTokenReceiver.EXTRA_TOKEN)) "present" else "none"} " +
                "assetTag=${enrollment.getString(EnrollmentTokenReceiver.EXTRA_ASSET_TAG, "")} " +
                "assignedTo=${enrollment.getString(EnrollmentTokenReceiver.EXTRA_ASSIGNED_TO, "")}",
        )
        val agent = Agent.prefs(this)
        SpikeLog.log(
            this,
            "INFO server=${agent.getString(Agent.KEY_SERVER, "-")} enrolled=${Agent.isEnrolled(this)} " +
                "lastCheckin=${agent.getString(Agent.KEY_LAST_CHECKIN, "never")} " +
                "policyApplied=v${PolicyEnforcer(this).appliedVersion} lastError=${agent.getString(Agent.KEY_LAST_ERROR, "none")}",
        )
    }

    private fun setHotspotBlocked(blocked: Boolean) {
        dpmCall(if (blocked) "block hotspot" else "unblock hotspot") {
            if (blocked) {
                dpm.addUserRestriction(admin, UserManager.DISALLOW_CONFIG_TETHERING)
            } else {
                dpm.clearUserRestriction(admin, UserManager.DISALLOW_CONFIG_TETHERING)
            }
        }
        logInterfaces()
    }

    /** No public API reports hotspot state; an active hotspot shows up as an extra interface (e.g. ap0, swlan0). */
    private fun logInterfaces() {
        val interfaces = NetworkInterface.getNetworkInterfaces()
        if (interfaces == null) {
            SpikeLog.log(this, "FAIL interfaces: getNetworkInterfaces returned null")
            return
        }
        val up = interfaces.toList()
            .filter { it.isUp && !it.isLoopback }
            .joinToString { iface ->
                val v4 = iface.inetAddresses.toList().filterIsInstance<Inet4Address>().joinToString("/") { it.hostAddress.orEmpty() }
                "${iface.name}[$v4]"
            }
        SpikeLog.log(this, "INFO interfaces up: $up")
    }

    // TYPE_MOBILE/TYPE_WIFI are deprecated but remain the documented inputs to querySummary.
    @Suppress("DEPRECATION")
    private fun queryUsage() {
        val nsm = getSystemService(NetworkStatsManager::class.java)
        val end = System.currentTimeMillis()
        val start = end - TimeUnit.HOURS.toMillis(24)
        for ((label, type) in listOf("mobile" to ConnectivityManager.TYPE_MOBILE, "wifi" to ConnectivityManager.TYPE_WIFI)) {
            try {
                // null subscriberId = all mobile networks / all wifi networks (documented for API 29+ / 31+).
                val stats = nsm.querySummary(type, null, start, end)
                if (stats == null) {
                    SpikeLog.log(this, "FAIL $label usage: querySummary returned null")
                    continue
                }
                val perUid = mutableMapOf<Int, Long>()
                val bucket = NetworkStats.Bucket()
                stats.use {
                    while (it.hasNextBucket()) {
                        it.getNextBucket(bucket)
                        perUid.merge(bucket.uid, bucket.rxBytes + bucket.txBytes, Long::plus)
                    }
                }
                val others = perUid.keys.count { it != applicationInfo.uid }
                val verdict = if (others > 0) "PASS" else "FAIL"
                SpikeLog.log(
                    this,
                    "$verdict $label usage: ${perUid.size} UIDs ($others not ours), total ${mb(perUid.values.sum())}, " +
                        "tethering ${mb(perUid[NetworkStats.Bucket.UID_TETHERING] ?: 0)}",
                )
                perUid.entries.sortedByDescending { it.value }.take(5).forEach {
                    SpikeLog.log(this, "  ${uidLabel(it.key)}: ${mb(it.value)}")
                }
            } catch (e: Exception) {
                SpikeLog.log(this, "FAIL $label usage: $e")
            }
        }
    }

    private fun setNetworkLogging(enabled: Boolean) {
        dpmCall(if (enabled) "enable network logging" else "disable network logging") {
            dpm.setNetworkLoggingEnabled(admin, enabled)
        }
        if (enabled) {
            SpikeLog.log(this, "INFO batches arrive after enough events or ~90 min; `adb shell dpm force-network-logs` forces one")
        }
    }

    private fun suspendApp(suspended: Boolean) {
        val pkg = packageInput.text.toString().trim()
        dpmCall("${if (suspended) "suspend" else "unsuspend"} $pkg") {
            val failed = dpm.setPackagesSuspended(admin, arrayOf(pkg), suspended)
            check(failed.isEmpty()) { "not changed: ${failed.toList()}" }
        }
    }

    private fun hideApp(hidden: Boolean) {
        val pkg = packageInput.text.toString().trim()
        dpmCall("${if (hidden) "hide" else "unhide"} $pkg") {
            check(dpm.setApplicationHidden(admin, pkg, hidden)) { "setApplicationHidden returned false" }
        }
    }

    private fun startSoak() {
        val request = PeriodicWorkRequestBuilder<SoakWorker>(15, TimeUnit.MINUTES).build()
        WorkManager.getInstance(this)
            .enqueueUniquePeriodicWork(SoakWorker.WORK_NAME, ExistingPeriodicWorkPolicy.KEEP, request)
        SpikeLog.log(this, "INFO soak started; leave the phone untouched and check the report after 24 h")
    }

    private fun releaseDeviceOwner() {
        dpmCall("release device owner") {
            if (dpm.isDeviceOwnerApp(packageName)) {
                dpm.clearUserRestriction(admin, UserManager.DISALLOW_CONFIG_TETHERING)
                @Suppress("DEPRECATION")
                dpm.clearDeviceOwnerApp(packageName)
            }
            // Also covers a failed set-device-owner, which leaves the app as a plain (uninstall-blocking) admin.
            if (dpm.isAdminActive(admin)) dpm.removeActiveAdmin(admin)
        }
        showStatus()
    }

    /** Lets testers send results without adb. Keeps the tail: intent extras are limited to well under 1 MB. */
    private fun shareLog() {
        val log = SpikeLog.read(this) + "\n" + SoakWorker.report(this)
        val send = Intent(Intent.ACTION_SEND)
            .setType("text/plain")
            .putExtra(Intent.EXTRA_SUBJECT, "Noahport spike log ${Build.MANUFACTURER} ${Build.MODEL}")
            .putExtra(Intent.EXTRA_TEXT, log.takeLast(200_000))
        startActivity(Intent.createChooser(send, "Share spike log"))
    }

    private fun dpmCall(name: String, block: () -> Unit) {
        try {
            block()
            SpikeLog.log(this, "PASS $name")
        } catch (e: Exception) {
            SpikeLog.log(this, "FAIL $name: $e")
        }
    }

    private fun uidLabel(uid: Int): String = when (uid) {
        NetworkStats.Bucket.UID_TETHERING -> "tethering"
        NetworkStats.Bucket.UID_REMOVED -> "removed apps"
        else -> packageManager.getPackagesForUid(uid)?.joinToString(",") ?: "uid $uid"
    }

    private fun mb(bytes: Long) = "%.1f MB".format(bytes / 1_048_576.0)

    private fun confirm(message: String, action: () -> Unit) {
        AlertDialog.Builder(this)
            .setMessage(message)
            .setPositiveButton("Yes") { _, _ -> action() }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun scrollLogToEnd() {
        logScroll.post { logScroll.fullScroll(View.FOCUS_DOWN) }
    }

    private fun LinearLayout.section(title: String) {
        addView(TextView(context).apply {
            text = title
            setTypeface(typeface, Typeface.BOLD)
            setPadding(0, dp(12), 0, dp(4))
        })
    }

    private fun LinearLayout.button(label: String, onClick: () -> Unit) {
        addView(Button(context).apply {
            text = label
            isAllCaps = false
            // A failing check is a result to record, not a reason to crash the test screen.
            setOnClickListener {
                try {
                    onClick()
                } catch (e: Exception) {
                    SpikeLog.log(context, "FAIL $label: $e")
                }
            }
        })
    }

    private fun dp(value: Int) = (value * resources.displayMetrics.density).toInt()

    private companion object {
        const val MATCH = LinearLayout.LayoutParams.MATCH_PARENT
    }
}
