package com.noahport.mdm.agent

import android.app.admin.ConnectEvent
import android.app.admin.DeviceAdminReceiver
import android.app.admin.DevicePolicyManager
import android.app.admin.DnsEvent
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import kotlin.concurrent.thread

class AdminReceiver : DeviceAdminReceiver() {

    override fun onEnabled(context: Context, intent: Intent) {
        SpikeLog.log(context, "Admin enabled")
    }

    override fun onProfileProvisioningComplete(context: Context, intent: Intent) {
        SpikeLog.log(context, "Provisioning complete")
    }

    override fun onNetworkLogsAvailable(
        context: Context,
        intent: Intent,
        batchToken: Long,
        networkLogsCount: Int,
    ) {
        SpikeLog.log(context, "Network logs available: token=$batchToken count=$networkLogsCount")
        // Delivered as a foreground broadcast; keep the retrieval off the receiver's main thread.
        val pending = goAsync()
        thread {
            try {
                retrieveBatch(context, batchToken)
            } finally {
                pending.finish()
            }
        }
    }

    private fun retrieveBatch(context: Context, batchToken: Long) {
        val dpm = context.getSystemService(DevicePolicyManager::class.java)
        val events = try {
            dpm.retrieveNetworkLogs(component(context), batchToken)
        } catch (e: SecurityException) {
            SpikeLog.log(context, "FAIL retrieveNetworkLogs: $e")
            return
        }
        if (events == null) {
            SpikeLog.log(context, "FAIL retrieveNetworkLogs returned null (stale token or logging disabled)")
            return
        }
        val dns = events.filterIsInstance<DnsEvent>()
        val connects = events.filterIsInstance<ConnectEvent>()
        SpikeLog.log(context, "PASS network log batch: ${events.size} events (${dns.size} DNS, ${connects.size} connect)")
        events.groupingBy { it.packageName }.eachCount().entries
            .sortedByDescending { it.value }.take(5)
            .forEach { SpikeLog.log(context, "  top package ${it.key}: ${it.value}") }
        dns.take(5).forEach {
            SpikeLog.log(context, "  DNS ${it.packageName} ${it.hostname} -> ${it.inetAddresses.joinToString { a -> a.hostAddress.orEmpty() }}")
        }
        connects.take(5).forEach {
            SpikeLog.log(context, "  CONNECT ${it.packageName} ${it.inetAddress.hostAddress}:${it.port}")
        }
    }

    companion object {
        fun component(context: Context) = ComponentName(context, AdminReceiver::class.java)
    }
}
