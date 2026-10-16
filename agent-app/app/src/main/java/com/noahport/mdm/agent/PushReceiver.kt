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
 * always fetched over the authenticated API. The same wake-up also comes over RealtimeChannel; Agent.wake runs the
 * check-in once, for whichever arrives first. High-priority FCM lets the phone use the network even in Doze.
 */
class PushReceiver : FirebaseMessagingService() {

    override fun onMessageReceived(message: RemoteMessage) {
        if (message.data["type"] == "checkin" && Agent.isEnrolled(this)) {
            Agent.wake(this, message.data["id"])
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
