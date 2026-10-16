package com.noahport.mdm.agent

import android.annotation.SuppressLint
import android.app.admin.DevicePolicyManager
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.BatteryManager
import android.os.Build
import android.telephony.TelephonyManager

/** Phone facts reported at enrollment and check-in. */
class DeviceInfo(private val context: Context) {

    private val packageInfo = context.packageManager.getPackageInfo(context.packageName, 0)

    val versionCode: Long get() = packageInfo.longVersionCode
    val versionName: String get() = packageInfo.versionName.orEmpty()

    private val isDeviceOwner: Boolean
        get() = context.getSystemService(DevicePolicyManager::class.java).isDeviceOwnerApp(context.packageName)

    /**
     * The hardware serial. A device owner may read it with READ_PHONE_STATE (granted to itself in
     * AgentService.grantOwnPermissions); null until that grant has happened.
     */
    val serial: String?
        @SuppressLint("HardwareIds", "MissingPermission")
        get() = runCatching { if (isDeviceOwner) Build.getSerial() else null }
            .getOrNull()?.takeIf { it.isNotBlank() && it != Build.UNKNOWN }

    /** The IMEI of the first SIM slot, under the same rule as [serial]; null on phones without a modem. */
    val imei: String?
        @SuppressLint("HardwareIds", "MissingPermission")
        get() = runCatching { if (isDeviceOwner) context.getSystemService(TelephonyManager::class.java)?.getImei(0) else null }
            .getOrNull()?.takeIf { it.isNotBlank() }

    val batteryPercent: Int
        get() = context.getSystemService(BatteryManager::class.java).getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)

    /**
     * Plugged into a charger (AC, USB, wireless or dock), which is what the charging icon means. Read from the sticky
     * battery broadcast: BatteryManager.isCharging() said false on the test phone while it was charging over USB.
     */
    val batteryCharging: Boolean
        get() = context.registerReceiver(null, IntentFilter(Intent.ACTION_BATTERY_CHANGED))
            ?.getIntExtra(BatteryManager.EXTRA_PLUGGED, 0)?.let { it != 0 } ?: false

    /** "wifi", "mobile", "none" or "other" for the current default network. */
    val networkType: String
        get() {
            val cm = context.getSystemService(ConnectivityManager::class.java)
            val caps = cm.getNetworkCapabilities(cm.activeNetwork) ?: return "none"
            return when {
                caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) -> "wifi"
                caps.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) -> "mobile"
                else -> "other"
            }
        }
}
