package com.noahport.mdm.agent

import android.app.admin.DevicePolicyManager
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import java.time.Instant

/**
 * USB enrollment (enroll/enroll.ps1): the laptop hands over the enrollment token and the device's label with
 * `adb shell am broadcast -n com.noahport.mdm.agent/.EnrollmentTokenReceiver --es enrollment_token ...`.
 * Stands in for the QR admin-extras bundle while Play Protect blocks QR provisioning.
 *
 * Protected by android.permission.DUMP (signature|privileged|development): adb shell holds it, third-party apps
 * can't. Result codes are read back by the script from `am broadcast` output.
 */
class EnrollmentTokenReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        val dpm = context.getSystemService(DevicePolicyManager::class.java)
        val token = intent.getStringExtra(EXTRA_TOKEN)
        when {
            !dpm.isDeviceOwnerApp(context.packageName) -> setResultCode(RESULT_NOT_DEVICE_OWNER)
            token.isNullOrBlank() -> setResultCode(RESULT_NO_TOKEN)
            else -> {
                val assetTag = intent.getStringExtra(EXTRA_ASSET_TAG).orEmpty()
                val assignedTo = intent.getStringExtra(EXTRA_ASSIGNED_TO).orEmpty()
                prefs(context).edit()
                    .putString(EXTRA_TOKEN, token)
                    .putString(EXTRA_ASSET_TAG, assetTag)
                    .putString(EXTRA_ASSIGNED_TO, assignedTo)
                    .putString(KEY_RECEIVED_AT, Instant.now().toString())
                    .apply()
                // Never log the token itself: in Phase 3 it is exchanged for the device credential.
                SpikeLog.log(context, "PASS USB enrollment token received assetTag=$assetTag assignedTo=$assignedTo")
                setResultCode(RESULT_STORED)
            }
        }
        if (resultCode != RESULT_STORED) SpikeLog.log(context, "FAIL USB enrollment token rejected: result=$resultCode")
    }

    companion object {
        const val EXTRA_TOKEN = "enrollment_token"
        const val EXTRA_ASSET_TAG = "asset_tag"
        const val EXTRA_ASSIGNED_TO = "assigned_to"
        private const val KEY_RECEIVED_AT = "received_at"

        const val RESULT_STORED = 1
        const val RESULT_NOT_DEVICE_OWNER = 2
        const val RESULT_NO_TOKEN = 3

        fun prefs(context: Context) = context.getSharedPreferences("enrollment", Context.MODE_PRIVATE)
    }
}
