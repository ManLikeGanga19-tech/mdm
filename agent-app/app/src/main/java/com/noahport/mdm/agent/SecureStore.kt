package com.noahport.mdm.agent

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * Secrets at rest (device API credential, reset-password token): AES-GCM with a non-exportable Android Keystore
 * key, stored in app-private prefs. Android's docs require the reset-password token never be stored in plaintext.
 */
object SecureStore {
    private const val KEY_ALIAS = "noahport-agent-secrets"
    private const val PREFS = "secure"

    fun put(context: Context, name: String, value: ByteArray) {
        val cipher = Cipher.getInstance("AES/GCM/NoPadding").apply { init(Cipher.ENCRYPT_MODE, key()) }
        val sealed = cipher.iv + cipher.doFinal(value)
        prefs(context).edit().putString(name, Base64.encodeToString(sealed, Base64.NO_WRAP)).apply()
    }

    fun get(context: Context, name: String): ByteArray? {
        val sealed = prefs(context).getString(name, null)?.let { Base64.decode(it, Base64.NO_WRAP) } ?: return null
        val cipher = Cipher.getInstance("AES/GCM/NoPadding").apply {
            init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(128, sealed, 0, 12))
        }
        return cipher.doFinal(sealed, 12, sealed.size - 12)
    }

    fun putString(context: Context, name: String, value: String) = put(context, name, value.toByteArray())

    fun getString(context: Context, name: String): String? = get(context, name)?.toString(Charsets.UTF_8)

    fun remove(context: Context, name: String) = prefs(context).edit().remove(name).apply()

    private fun prefs(context: Context) = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    private fun key(): SecretKey {
        val store = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (store.getEntry(KEY_ALIAS, null) as? KeyStore.SecretKeyEntry)?.let { return it.secretKey }
        return KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore").apply {
            init(
                KeyGenParameterSpec.Builder(KEY_ALIAS, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                    .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                    .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                    .setKeySize(256)
                    .build(),
            )
        }.generateKey()
    }
}
