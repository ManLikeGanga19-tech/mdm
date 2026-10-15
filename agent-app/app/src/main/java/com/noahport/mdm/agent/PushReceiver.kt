package com.noahport.mdm.agent

import android.content.Context
import com.google.android.gms.tasks.Tasks
import com.google.firebase.FirebaseApp
import com.google.firebase.messaging.FirebaseMessaging
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import java.util.concurrent.TimeUnit

/**
 * Firebase wake-ups from the server's PushService. A message only says "check in now"; policy and commands are
 * always fetched over the authenticated API. High-priority FCM lets the expedited check-in run right away.
 */
class PushReceiver : FirebaseMessagingService() {

    override fun onMessageReceived(message: RemoteMessage) {
        if (message.data["type"] == "checkin" && Agent.isEnrolled(this)) {
            Agent.checkInNow(this, expedited = true)
        }
    }

    /** A new token must reach the server, or pushes go to the old one. */
    override fun onNewToken(token: String) {
        if (Agent.isEnrolled(this)) Agent.checkInNow(this)
    }

    companion object {
        /** The current FCM token, or null when Firebase isn't configured in this build. Call from a worker thread. */
        fun currentToken(context: Context): String? {
            if (FirebaseApp.getApps(context).isEmpty()) return null
            return runCatching { Tasks.await(FirebaseMessaging.getInstance().token, 20, TimeUnit.SECONDS) }.getOrNull()
        }
    }
}
