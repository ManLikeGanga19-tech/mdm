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

/** Phone facts reported at enrollment and check-in. */
class DeviceInfo(private val context: Context) {

    private val packageInfo = context.packageManager.getPackageInfo(context.packageName, 0)

    val versionCode: Long get() = packageInfo.longVersionCode
    val versionName: String get() = packageInfo.versionName.orEmpty()

    /** The hardware serial. Build.getSerial() is allowed for the device owner of a fully managed device (SDK docs). */
    val serial: String?
        @SuppressLint("HardwareIds", "MissingPermission")
        get() = runCatching {
            if (context.getSystemService(DevicePolicyManager::class.java).isDeviceOwnerApp(context.packageName)) Build.getSerial() else null
        }.getOrNull()?.takeIf { it != Build.UNKNOWN }

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
