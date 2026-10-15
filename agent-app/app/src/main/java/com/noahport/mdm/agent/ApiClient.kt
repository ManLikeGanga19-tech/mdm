package com.noahport.mdm.agent

import org.json.JSONObject
import java.io.IOException
import java.net.HttpURLConnection
import java.net.URL

/** Minimal JSON-over-HTTPS client for the device API (ARCHITECTURE.md §7). Blocking: call from workers only. */
class ApiClient(private val baseUrl: String, private val deviceToken: String? = null) {

    class Response(val code: Int, val body: JSONObject?)

    init {
        require(baseUrl.startsWith("https://")) { "Server address must be https://" }
    }

    @Throws(IOException::class)
    fun post(path: String, json: JSONObject): Response {
        val connection = URL(baseUrl.trimEnd('/') + "/api/device/v1/" + path).openConnection() as HttpURLConnection
        try {
            connection.requestMethod = "POST"
            connection.connectTimeout = 20_000
            connection.readTimeout = 30_000
            connection.doOutput = true
            connection.setRequestProperty("Content-Type", "application/json")
            connection.setRequestProperty("Accept", "application/json")
            deviceToken?.let { connection.setRequestProperty("Authorization", "Bearer $it") }
            connection.outputStream.use { it.write(json.toString().toByteArray()) }

            val code = connection.responseCode
            val stream = if (code in 200..299) connection.inputStream else connection.errorStream
            val text = stream?.bufferedReader()?.use { it.readText() }.orEmpty()
            val body = runCatching { if (text.isNotBlank()) JSONObject(text) else null }.getOrNull()
            return Response(code, body)
        } finally {
            connection.disconnect()
        }
    }
}
