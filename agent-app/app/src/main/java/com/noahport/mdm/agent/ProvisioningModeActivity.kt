package com.noahport.mdm.agent

import android.app.Activity
import android.app.admin.DevicePolicyManager
import android.content.Intent
import android.os.Bundle
import android.os.PersistableBundle

/** Answers Android 12+ QR provisioning: this agent only supports fully managed (device owner) mode. */
class ProvisioningModeActivity : Activity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val allowed = intent.getIntArrayExtra(DevicePolicyManager.EXTRA_PROVISIONING_ALLOWED_PROVISIONING_MODES)
        val extras = intent.getParcelableExtra(
            DevicePolicyManager.EXTRA_PROVISIONING_ADMIN_EXTRAS_BUNDLE,
            PersistableBundle::class.java,
        )
        SpikeLog.log(this, "GET_PROVISIONING_MODE allowed=${allowed?.toList()} adminExtras=${extras?.describe()}")

        setResult(
            RESULT_OK,
            Intent()
                .putExtra(
                    DevicePolicyManager.EXTRA_PROVISIONING_MODE,
                    DevicePolicyManager.PROVISIONING_MODE_FULLY_MANAGED_DEVICE,
                )
                // Otherwise provisioning disables most system apps (camera, browser, ...).
                .putExtra(DevicePolicyManager.EXTRA_PROVISIONING_LEAVE_ALL_SYSTEM_APPS_ENABLED, true),
        )
        finish()
    }
}

internal fun PersistableBundle.describe(): String =
    keySet().joinToString(prefix = "{", postfix = "}") { "$it=${getString(it) ?: "?"}" }
