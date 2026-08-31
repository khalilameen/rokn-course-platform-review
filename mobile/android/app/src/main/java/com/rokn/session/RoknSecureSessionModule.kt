package com.rokn.session

import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import com.facebook.react.bridge.Promise
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.bridge.ReactContextBaseJavaModule
import com.facebook.react.bridge.ReactMethod
import java.security.KeyStore
import javax.crypto.AEADBadTagException
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

class RoknSecureSessionModule(
  reactContext: ReactApplicationContext,
) : ReactContextBaseJavaModule(reactContext) {
  override fun getName(): String = "RoknSecureSession"

  private val preferences by lazy {
    reactApplicationContext.getSharedPreferences(PREFERENCES_NAME, 0)
  }

  private fun validateKey(key: String) {
    require(KEY_PATTERN.matches(key)) { "Invalid secure-session key" }
  }

  private fun getOrCreateSecretKey(): SecretKey {
    val keyStore = KeyStore.getInstance(KEYSTORE_PROVIDER).apply { load(null) }
    (keyStore.getKey(KEY_ALIAS, null) as? SecretKey)?.let { return it }

    val generator = KeyGenerator.getInstance(
      KeyProperties.KEY_ALGORITHM_AES,
      KEYSTORE_PROVIDER,
    )
    generator.init(
      KeyGenParameterSpec.Builder(
        KEY_ALIAS,
        KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT,
      )
        .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
        .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
        .setRandomizedEncryptionRequired(true)
        .build(),
    )
    return generator.generateKey()
  }

  @ReactMethod
  fun setItem(key: String, value: String, promise: Promise) {
    try {
      validateKey(key)
      val cipher = Cipher.getInstance(TRANSFORMATION)
      cipher.init(Cipher.ENCRYPT_MODE, getOrCreateSecretKey())
      val encrypted = cipher.doFinal(value.toByteArray(Charsets.UTF_8))
      val payload = listOf(
        PAYLOAD_VERSION,
        Base64.encodeToString(cipher.iv, Base64.NO_WRAP),
        Base64.encodeToString(encrypted, Base64.NO_WRAP),
      ).joinToString(".")
      check(preferences.edit().putString(key, payload).commit()) {
        "Encrypted session preference was not committed"
      }
      promise.resolve(null)
    } catch (error: Throwable) {
      promise.reject("ROKN_SECURE_SESSION_WRITE_FAILED", error)
    }
  }

  @ReactMethod
  fun getItem(key: String, promise: Promise) {
    try {
      validateKey(key)
      val payload = preferences.getString(key, null)
      if (payload == null) {
        promise.resolve(null)
        return
      }
      val parts = payload.split('.')
      check(parts.size == 3 && parts[0] == PAYLOAD_VERSION) {
        "Unsupported secure-session payload"
      }
      val cipher = Cipher.getInstance(TRANSFORMATION)
      cipher.init(
        Cipher.DECRYPT_MODE,
        getOrCreateSecretKey(),
        GCMParameterSpec(GCM_TAG_BITS, Base64.decode(parts[1], Base64.NO_WRAP)),
      )
      val decrypted = cipher.doFinal(Base64.decode(parts[2], Base64.NO_WRAP))
      promise.resolve(String(decrypted, Charsets.UTF_8))
    } catch (error: AEADBadTagException) {
      // A restored preference cannot be decrypted after Android creates a new
      // app key. Remove the unusable value instead of repeatedly failing boot.
      preferences.edit().remove(key).commit()
      promise.resolve(null)
    } catch (error: Throwable) {
      promise.reject("ROKN_SECURE_SESSION_READ_FAILED", error)
    }
  }

  @ReactMethod
  fun deleteItem(key: String, promise: Promise) {
    try {
      validateKey(key)
      check(preferences.edit().remove(key).commit()) {
        "Secure-session preference was not removed"
      }
      promise.resolve(null)
    } catch (error: Throwable) {
      promise.reject("ROKN_SECURE_SESSION_DELETE_FAILED", error)
    }
  }

  companion object {
    private const val PREFERENCES_NAME = "rokn_secure_session_v1"
    private const val KEYSTORE_PROVIDER = "AndroidKeyStore"
    private const val KEY_ALIAS = "rokn_secure_session_key_v1"
    private const val TRANSFORMATION = "AES/GCM/NoPadding"
    private const val GCM_TAG_BITS = 128
    private const val PAYLOAD_VERSION = "v1"
    private val KEY_PATTERN = Regex("^[A-Za-z0-9._-]{1,128}$")
  }
}
