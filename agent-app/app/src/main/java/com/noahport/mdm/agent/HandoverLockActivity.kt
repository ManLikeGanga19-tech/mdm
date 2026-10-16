package com.noahport.mdm.agent

import android.app.Activity
import android.content.SharedPreferences
import android.graphics.Color
import android.graphics.Typeface
import android.os.Bundle
import android.view.Gravity
import android.view.WindowInsets
import android.widget.LinearLayout
import android.widget.TextView
import android.window.OnBackInvokedDispatcher

/** Full-screen notice shown while the phone is under the handover lock, pinned with lock task mode. */
class HandoverLockActivity : Activity() {

    private val watcher = SharedPreferences.OnSharedPreferenceChangeListener { _, _ -> finishIfUnlocked() }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(buildUi())
        // Back (button or gesture) does nothing while locked.
        onBackInvokedDispatcher.registerOnBackInvokedCallback(OnBackInvokedDispatcher.PRIORITY_DEFAULT) { }
        // Once, in case the handover was just recorded. (Not on every resume: a check-in re-applies the lock and
        // would bring this screen back to the front, looping.) Unlocking normally arrives by push.
        if (Agent.isEnrolled(this)) Agent.checkInNow(this)
    }

    override fun onResume() {
        super.onResume()
        HandoverLock.prefs(this).registerOnSharedPreferenceChangeListener(watcher)
        if (!finishIfUnlocked()) {
            runCatching { startLockTask() } // allowed without a prompt: the package is on the lock task list
        }
    }

    override fun onPause() {
        HandoverLock.prefs(this).unregisterOnSharedPreferenceChangeListener(watcher)
        super.onPause()
    }

    private fun finishIfUnlocked(): Boolean {
        if (HandoverLock.isLocked(this)) return false
        runCatching { stopLockTask() }
        finish()
        return true
    }

    private fun buildUi() = LinearLayout(this).apply {
        orientation = LinearLayout.VERTICAL
        gravity = Gravity.CENTER
        setBackgroundColor(Color.parseColor("#B91C1C"))
        val pad = (28 * resources.displayMetrics.density).toInt()
        setPadding(pad, pad, pad, pad)
        setOnApplyWindowInsetsListener { v, insets ->
            val bars = insets.getInsets(WindowInsets.Type.systemBars())
            v.setPadding(pad + bars.left, pad + bars.top, pad + bars.right, pad + bars.bottom)
            insets
        }
        addView(TextView(context).apply {
            text = getString(R.string.handover_lock_title)
            setTextColor(Color.WHITE)
            textSize = 26f
            setTypeface(typeface, Typeface.BOLD)
            gravity = Gravity.CENTER
        })
        addView(TextView(context).apply {
            text = getString(R.string.handover_lock_message)
            setTextColor(Color.WHITE)
            textSize = 18f
            gravity = Gravity.CENTER
            setPadding(0, pad, 0, pad)
        })
        addView(TextView(context).apply {
            val tag = EnrollmentTokenReceiver.prefs(context).getString(EnrollmentTokenReceiver.EXTRA_ASSET_TAG, "").orEmpty()
            text = getString(R.string.handover_lock_footer, tag)
            setTextColor(Color.parseColor("#FECACA"))
            textSize = 14f
            gravity = Gravity.CENTER
        })
    }
}
