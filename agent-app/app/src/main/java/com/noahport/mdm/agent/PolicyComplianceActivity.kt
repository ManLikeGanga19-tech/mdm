package com.noahport.mdm.agent

import android.app.Activity
import android.app.admin.DevicePolicyManager
import android.os.Bundle
import android.os.PersistableBundle

/** Final DPC step of Android 12+ provisioning. In Phase 3 this is where the agent enrolls with the backend. */
class PolicyComplianceActivity : Activity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val extras = intent.getParcelableExtra(
            DevicePolicyManager.EXTRA_PROVISIONING_ADMIN_EXTRAS_BUNDLE,
            PersistableBundle::class.java,
        )
        val dpm = getSystemService(DevicePolicyManager::class.java)
        SpikeLog.log(
            this,
            "ADMIN_POLICY_COMPLIANCE deviceOwner=${dpm.isDeviceOwnerApp(packageName)} adminExtras=${extras?.describe()}",
        )
        setResult(RESULT_OK)
        finish()
    }
}
