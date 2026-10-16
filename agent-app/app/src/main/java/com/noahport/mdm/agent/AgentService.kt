package com.noahport.mdm.agent

import android.Manifest
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.app.admin.DevicePolicyManager
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.ServiceInfo
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.os.BatteryManager
import android.os.Build
import android.os.Handler
import android.os.IBinder
import android.os.Looper

/**
 * Always-on agent service: reports to the server the moment the phone's state changes (charger plugged in or out,
 * Wi-Fi <-> mobile data, every 5% of battery), instead of waiting for the 15-minute check-in. Android no longer
 * delivers these events to apps that aren't running, so the agent stays running as a foreground service of type
 * systemExempted, which Android allows for device owner apps (ServiceInfo.FOREGROUND_SERVICE_TYPE_SYSTEM_EXEMPTED).
 */
class AgentService : Service() {

    private val handler = Handler(Looper.getMainLooper())
    private var lastNetwork: String? = null
    private var lastBatteryStep = -1
    private var lastCharging: Boolean? = null

    private val reportSoon = Runnable { Agent.checkInNow(this, expedited = true) }

    private val live by lazy { LiveSampler(this) { handler.post { updateNotification(live = false) } } }

    private val power = object : BroadcastReceiver() {
        override fun onReceive(context: Context, intent: Intent) {
            when (intent.action) {
                Intent.ACTION_POWER_CONNECTED, Intent.ACTION_POWER_DISCONNECTED -> changed()
                Intent.ACTION_BATTERY_CHANGED -> {
                    val level = intent.getIntExtra(BatteryManager.EXTRA_LEVEL, -1)
                    val scale = intent.getIntExtra(BatteryManager.EXTRA_SCALE, 100)
                    val charging = intent.getIntExtra(BatteryManager.EXTRA_PLUGGED, 0) != 0
                    val step = if (level >= 0) level * 100 / scale / 5 else -1
                    val first = lastBatteryStep == -1
                    if (!first && (step != lastBatteryStep || charging != lastCharging)) changed()
                    lastBatteryStep = step
                    lastCharging = charging
                }
            }
        }
    }

    private val network = object : ConnectivityManager.NetworkCallback() {
        override fun onCapabilitiesChanged(network: Network, caps: NetworkCapabilities) {
            val type = when {
                caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) -> "wifi"
                caps.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) -> "mobile"
                else -> "other"
            }
            if (lastNetwork != null && type != lastNetwork) changed()
            lastNetwork = type
        }
    }

    override fun onCreate() {
        super.onCreate()
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
                startForeground(NOTIFICATION_ID, notification(), ServiceInfo.FOREGROUND_SERVICE_TYPE_SYSTEM_EXEMPTED)
            } else {
                startForeground(NOTIFICATION_ID, notification()) // Android 13 has no service types to declare
            }
        } catch (e: SecurityException) {
            // Not expected for a device owner (see class doc); the 15-minute check-in and pushes still work.
            SpikeLog.log(this, "FAIL agent service not allowed: $e")
            stopSelf()
            return
        }
        registerReceiver(power, IntentFilter().apply {
            addAction(Intent.ACTION_POWER_CONNECTED)
            addAction(Intent.ACTION_POWER_DISCONNECTED)
            addAction(Intent.ACTION_BATTERY_CHANGED)
        })
        getSystemService(ConnectivityManager::class.java).registerDefaultNetworkCallback(network)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_LIVE) {
            live.start(intent.getIntExtra(EXTRA_SECONDS, 600).coerceIn(10, 1800))
            updateNotification(live = true)
        }
        return START_STICKY
    }

    override fun onDestroy() {
        runCatching { unregisterReceiver(power) }
        runCatching { getSystemService(ConnectivityManager::class.java).unregisterNetworkCallback(network) }
        handler.removeCallbacks(reportSoon)
        live.shutdown()
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null

    /** Debounced: plugging in fires several events within a second; one report is enough. */
    private fun changed() {
        if (!Agent.isEnrolled(this)) return
        handler.removeCallbacks(reportSoon)
        handler.postDelayed(reportSoon, 1500)
    }

    /** While live view runs the notice says so (transparency; Kenya Data Protection Act). */
    private fun updateNotification(live: Boolean) {
        getSystemService(NotificationManager::class.java).notify(NOTIFICATION_ID, notification(live))
    }

    private fun notification(live: Boolean = false): Notification {
        val manager = getSystemService(NotificationManager::class.java)
        manager.createNotificationChannel(
            NotificationChannel(CHANNEL, getString(R.string.service_channel), NotificationManager.IMPORTANCE_MIN),
        )
        val open = PendingIntent.getActivity(
            this, 0, Intent(this, MainActivity::class.java), PendingIntent.FLAG_IMMUTABLE,
        )
        return Notification.Builder(this, CHANNEL)
            .setSmallIcon(android.R.drawable.ic_lock_lock)
            .setContentTitle(getString(R.string.notice_title))
            .setContentText(getString(if (live) R.string.service_text_live else R.string.service_text))
            .setContentIntent(open)
            .setOngoing(true)
            .build()
    }

    companion object {
        private const val CHANNEL = "agent"
        private const val NOTIFICATION_ID = 1
        private const val ACTION_LIVE = "com.noahport.mdm.agent.LIVE"
        private const val EXTRA_SECONDS = "seconds"

        /** Starts (or extends) live view for [seconds]; the service shows that IT is watching. */
        fun startLive(context: Context, seconds: Int) {
            context.startForegroundService(Intent(context, AgentService::class.java).setAction(ACTION_LIVE).putExtra(EXTRA_SECONDS, seconds))
        }

        /**
         * Device owners may grant their own runtime permissions: notifications (otherwise the notice is hidden from the
         * shade) and phone state (serial number and IMEI, see DeviceInfo).
         */
        fun grantOwnPermissions(context: Context) {
            val dpm = context.getSystemService(DevicePolicyManager::class.java)
            for (permission in listOf(Manifest.permission.POST_NOTIFICATIONS, Manifest.permission.READ_PHONE_STATE)) {
                runCatching {
                    dpm.setPermissionGrantState(
                        AdminReceiver.component(context), context.packageName, permission, DevicePolicyManager.PERMISSION_GRANT_STATE_GRANTED,
                    )
                }.onFailure { SpikeLog.log(context, "FAIL granting $permission: $it") }
            }
        }

        /** Starts the service if it isn't running (called on enrollment, app open, boot and every check-in). */
        fun ensureRunning(context: Context) {
            if (!Agent.isEnrolled(context)) return
            grantOwnPermissions(context)
            runCatching { context.startForegroundService(Intent(context, AgentService::class.java)) }
                .onFailure { SpikeLog.log(context, "FAIL starting agent service: $it") }
        }
    }
}
