package com.noahport.mdm.agent

import android.app.admin.DevicePolicyManager
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import java.time.Instant

/**
 * USB enrollment (NoahportEnroll.exe): the laptop hands over the server address, the enrollment code and the
 * phone's label with `adb shell am broadcast -n com.noahport.mdm.agent/.EnrollmentTokenReceiver --es ...`.
 * Stands in for the QR admin-extras bundle while Play Protect blocks QR provisioning. The agent then enrolls with
 * the server in the background (EnrollWorker).
 *
 * Protected by android.permission.DUMP (signature|privileged|development): adb shell holds it, third-party apps
 * can't. Result codes are read back by the tool from `am broadcast` output.
 */
class EnrollmentTokenReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        val dpm = context.getSystemService(DevicePolicyManager::class.java)
        val token = intent.getStringExtra(EXTRA_TOKEN)
        val server = intent.getStringExtra(EXTRA_SERVER)
        when {
            !dpm.isDeviceOwnerApp(context.packageName) -> setResultCode(RESULT_NOT_DEVICE_OWNER)
            token.isNullOrBlank() -> setResultCode(RESULT_NO_TOKEN)
            server != null && !server.startsWith("https://") -> setResultCode(RESULT_BAD_SERVER)
            else -> {
                val assetTag = intent.getStringExtra(EXTRA_ASSET_TAG).orEmpty()
                SecureStore.putString(context, SECRET_ENROLLMENT_CODE, token)
                prefs(context).edit()
                    .putString(EXTRA_TOKEN, "present") // the code itself is kept encrypted in SecureStore
                    .putString(EXTRA_SERVER, server)
                    .putString(EXTRA_ASSET_TAG, assetTag)
                    .putString(KEY_RECEIVED_AT, Instant.now().toString())
                    .apply()
                // Never log the code itself: it admits phones to the fleet.
                SpikeLog.log(context, "PASS USB enrollment code received assetTag=$assetTag server=$server")
                if (server != null) Agent.enrollSoon(context)
                setResultCode(RESULT_STORED)
            }
        }
        if (resultCode != RESULT_STORED) SpikeLog.log(context, "FAIL USB enrollment code rejected: result=$resultCode")
    }

    companion object {
        const val EXTRA_TOKEN = "enrollment_token"
        const val EXTRA_SERVER = "api_base_url"
        const val EXTRA_ASSET_TAG = "asset_tag"
        const val SECRET_ENROLLMENT_CODE = "enrollment_code"
        private const val KEY_RECEIVED_AT = "received_at"

        /** Who holds the phone now, from the server at each check-in (set by the shift handover). */
        const val KEY_HOLDER = "holder"

        const val RESULT_STORED = 1
        const val RESULT_NOT_DEVICE_OWNER = 2
        const val RESULT_NO_TOKEN = 3
        const val RESULT_BAD_SERVER = 4

        fun prefs(context: Context) = context.getSharedPreferences("enrollment", Context.MODE_PRIVATE)
    }
}
