package com.noahport.mdm.ussdprobe

import android.Manifest
import android.app.Activity
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Typeface
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.telephony.SubscriptionInfo
import android.telephony.SubscriptionManager
import android.telephony.TelephonyManager
import android.util.Log
import android.view.WindowInsets
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import java.io.File
import java.time.Instant

/**
 * B0 bundle test: for each SIM in the phone, send a USSD code with TelephonyManager.sendUssdRequest (Android 8+,
 * single request/response, no dialler screen) and log exactly what comes back, or the failure code. The answers decide
 * whether agent 1.1.0 can read the data balance itself or must rely on recorded top-ups + its own usage counter.
 *
 * What to note per carrier: did text come back (copy it all), how long it took, whether an SMS arrived instead or
 * as well, and whether the reply is a menu asking for another choice.
 */
class ProbeActivity : Activity() {

    private lateinit var logView: TextView
    private lateinit var sims: LinearLayout
    private val main = Handler(Looper.getMainLooper())

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(buildUi())
        logView.text = logFile().takeIf { it.exists() }?.readText().orEmpty()
        val missing = PERMISSIONS.filter { checkSelfPermission(it) != PackageManager.PERMISSION_GRANTED }
        if (missing.isEmpty()) showSims() else requestPermissions(missing.toTypedArray(), 1)
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        if (grantResults.all { it == PackageManager.PERMISSION_GRANTED }) {
            showSims()
        } else {
            log("FAIL permissions refused: allow Phone for USSD probe in Settings > Apps, then reopen")
        }
    }

    private fun buildUi() = ScrollView(this).apply {
        val pad = dp(12)
        setOnApplyWindowInsetsListener { v, insets ->
            val bars = insets.getInsets(WindowInsets.Type.systemBars())
            v.setPadding(pad + bars.left, pad + bars.top, pad + bars.right, pad + bars.bottom)
            insets
        }
        addView(LinearLayout(context).apply {
            orientation = LinearLayout.VERTICAL
            addView(TextView(context).apply {
                text = "USSD probe (Noahport B0 test)\nTap a code for each SIM. Wait up to 30 s, then note whether an SMS also arrived."
                textSize = 15f
            })
            sims = LinearLayout(context).apply { orientation = LinearLayout.VERTICAL }
            addView(sims)
            addView(Button(context).apply {
                text = "Share results (WhatsApp, email, ...)"
                setOnClickListener { share() }
            })
            addView(Button(context).apply {
                text = "Clear results"
                setOnClickListener {
                    logFile().delete()
                    logView.text = ""
                }
            })
            logView = TextView(context).apply {
                typeface = Typeface.MONOSPACE
                textSize = 11f
                setTextIsSelectable(true)
            }
            addView(logView)
        })
    }

    private fun showSims() {
        if (checkSelfPermission(Manifest.permission.READ_PHONE_STATE) != PackageManager.PERMISSION_GRANTED) return
        sims.removeAllViews()
        val manager = getSystemService(SubscriptionManager::class.java)
        val active = manager.activeSubscriptionInfoList.orEmpty()
        val dataSub = SubscriptionManager.getDefaultDataSubscriptionId()
        log("INFO ${Build.MANUFACTURER} ${Build.MODEL} Android ${Build.VERSION.RELEASE}; ${active.size} SIM(s); mobile data on subId $dataSub")
        if (active.isEmpty()) log("FAIL no active SIM")
        for (sim in active) {
            val carrier = Carrier.of(sim)
            log(
                "INFO SIM slot ${sim.simSlotIndex + 1}: subId=${sim.subscriptionId} mcc=${sim.mccString} mnc=${sim.mncString} " +
                    "carrier=\"${sim.carrierName}\" name=\"${sim.displayName}\" -> ${carrier?.label ?: "unknown carrier"}" +
                    if (sim.subscriptionId == dataSub) " (mobile data SIM)" else "",
            )
            sims.addView(TextView(this).apply {
                text = "\nSIM ${sim.simSlotIndex + 1}: ${carrier?.label ?: sim.carrierName}" + if (sim.subscriptionId == dataSub) " (mobile data)" else ""
                textSize = 16f
                setTypeface(typeface, Typeface.BOLD)
            })
            for (code in carrier?.codes.orEmpty()) {
                sims.addView(Button(this).apply {
                    text = "Send $code"
                    setOnClickListener { send(sim, code) }
                })
            }
            val custom = EditText(this).apply {
                hint = "Other code, e.g. *544#"
                setSingleLine()
            }
            sims.addView(custom)
            sims.addView(Button(this).apply {
                text = "Send the code typed above"
                setOnClickListener { custom.text.toString().trim().takeIf { it.isNotEmpty() }?.let { send(sim, it) } }
            })
        }
    }

    private fun send(sim: SubscriptionInfo, code: String) {
        if (checkSelfPermission(Manifest.permission.CALL_PHONE) != PackageManager.PERMISSION_GRANTED) {
            log("FAIL CALL_PHONE not granted")
            return
        }
        val telephony = getSystemService(TelephonyManager::class.java).createForSubscriptionId(sim.subscriptionId)
        val started = System.currentTimeMillis()
        var answered = false
        log("INFO SIM ${sim.simSlotIndex + 1} sending $code ...")
        try {
            telephony.sendUssdRequest(code, object : TelephonyManager.UssdResponseCallback() {
                override fun onReceiveUssdResponse(tm: TelephonyManager, request: String, response: CharSequence) {
                    answered = true
                    log("PASS SIM ${sim.simSlotIndex + 1} $request replied after ${System.currentTimeMillis() - started} ms:\n<<<\n$response\n>>>")
                }

                override fun onReceiveUssdResponseFailed(tm: TelephonyManager, request: String, failureCode: Int) {
                    answered = true
                    val reason = when (failureCode) {
                        TelephonyManager.USSD_RETURN_FAILURE -> "USSD_RETURN_FAILURE (network refused or the code needs a menu)"
                        TelephonyManager.USSD_ERROR_SERVICE_UNAVAIL -> "USSD_ERROR_SERVICE_UNAVAIL (no service / phone busy)"
                        else -> "code $failureCode"
                    }
                    log("FAIL SIM ${sim.simSlotIndex + 1} $request failed after ${System.currentTimeMillis() - started} ms: $reason")
                }
            }, main)
        } catch (e: Exception) {
            log("FAIL SIM ${sim.simSlotIndex + 1} $code threw $e")
            return
        }
        main.postDelayed({ if (!answered) log("FAIL SIM ${sim.simSlotIndex + 1} $code: no reply after 30 s (check for an SMS)") }, 30_000)
    }

    private fun log(message: String) {
        val line = "${Instant.now()} $message"
        Log.i("UssdProbe", message)
        logFile().appendText(line + "\n")
        runOnUiThread { logView.append(line + "\n") }
    }

    private fun share() {
        startActivity(Intent.createChooser(Intent(Intent.ACTION_SEND).apply {
            type = "text/plain"
            putExtra(Intent.EXTRA_SUBJECT, "USSD probe results")
            putExtra(Intent.EXTRA_TEXT, logFile().takeIf { it.exists() }?.readText().orEmpty())
        }, "Share results"))
    }

    private fun logFile() = File(filesDir, "probe.log")

    private fun dp(value: Int) = (value * resources.displayMetrics.density).toInt()

    /** Kenyan networks by mobile network code (MCC 639). Codes to test, from the user (2026-10-17); not verified yet. */
    private enum class Carrier(val label: String, val mnc: String, val codes: List<String>) {
        Safaricom("Safaricom", "02", listOf("*544*44#", "*450#", "*144#")),
        Airtel("Airtel", "03", listOf("*544*3#", "*131#", "*133#"));

        companion object {
            fun of(sim: SubscriptionInfo) = entries.firstOrNull { sim.mccString == "639" && sim.mncString == it.mnc }
        }
    }

    private companion object {
        val PERMISSIONS = listOf(Manifest.permission.CALL_PHONE, Manifest.permission.READ_PHONE_STATE)
    }
}
