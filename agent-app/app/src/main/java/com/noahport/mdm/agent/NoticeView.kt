package com.noahport.mdm.agent

import android.app.admin.DevicePolicyManager
import android.content.Context
import android.graphics.Typeface
import android.widget.Button
import android.widget.LinearLayout
import android.widget.TextView
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter

/**
 * The staff-facing "This phone is managed by Noahport" notice: what is and isn't collected, what IT can do, and the
 * phone's label. It is the whole screen in release builds. "I understand" stores when it was acknowledged (stored in
 * UTC; reported to the backend in Phase 3).
 */
class NoticeView(context: Context) : LinearLayout(context) {

    init {
        orientation = VERTICAL
        addView(TextView(context).apply {
            text = context.getString(R.string.notice_title)
            textSize = 22f
            setTypeface(typeface, Typeface.BOLD)
            setPadding(0, 0, 0, dp(12))
        })
        addView(TextView(context).apply {
            text = context.getText(R.string.notice_body) // getText keeps the <b> headings
            textSize = 15f
            setLineSpacing(0f, 1.15f)
        })
        addView(TextView(context).apply {
            text = deviceInfo(context)
            textSize = 13f
            setPadding(0, dp(16), 0, dp(8))
        })
        addView(Button(context).apply {
            isAllCaps = false
            val prefs = EnrollmentTokenReceiver.prefs(context)
            fun show(ackedAt: String?) {
                if (ackedAt == null) {
                    text = context.getString(R.string.notice_acknowledge)
                } else {
                    val local = Instant.parse(ackedAt).atZone(ZoneId.systemDefault())
                    text = context.getString(R.string.notice_acknowledged, DateTimeFormatter.ofPattern("d MMM yyyy, HH:mm").format(local))
                    isEnabled = false
                }
            }
            show(prefs.getString(KEY_ACKNOWLEDGED_AT, null))
            setOnClickListener {
                val now = Instant.now().toString() // Instant prints UTC
                prefs.edit().putString(KEY_ACKNOWLEDGED_AT, now).apply()
                SpikeLog.log(context, "INFO staff notice acknowledged")
                show(now)
            }
        })
    }

    private fun deviceInfo(context: Context): String {
        val prefs = EnrollmentTokenReceiver.prefs(context)
        val notSet = context.getString(R.string.notice_not_set)
        val owner = context.getSystemService(DevicePolicyManager::class.java).isDeviceOwnerApp(context.packageName)
        @Suppress("DEPRECATION")
        val version = context.packageManager.getPackageInfo(context.packageName, 0).versionName
        return context.getString(
            R.string.notice_device_info,
            prefs.getString(EnrollmentTokenReceiver.EXTRA_ASSET_TAG, "").orEmpty().ifEmpty { notSet },
            prefs.getString(EnrollmentTokenReceiver.EXTRA_ASSIGNED_TO, "").orEmpty().ifEmpty { notSet },
            context.getString(if (owner) R.string.notice_yes else R.string.notice_no),
            version,
            Agent.prefs(context).getString(Agent.KEY_LAST_CHECKIN, null)
                ?.let { DateTimeFormatter.ofPattern("d MMM yyyy, HH:mm").format(Instant.parse(it).atZone(ZoneId.systemDefault())) }
                ?: context.getString(R.string.notice_not_synced),
        )
    }

    private fun dp(value: Int) = (value * resources.displayMetrics.density).toInt()

    companion object {
        const val KEY_ACKNOWLEDGED_AT = "notice_acknowledged_at"
    }
}
