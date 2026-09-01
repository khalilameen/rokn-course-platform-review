package com.rokn.session

import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyPermanentlyInvalidatedException
import android.security.keystore.KeyProperties
import android.util.Base64
import com.facebook.react.bridge.Promise
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.bridge.ReactContextBaseJavaModule
import com.facebook.react.bridge.ReactMethod
import java.security.KeyStore
import java.security.UnrecoverableKeyException
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

  private fun resetUnusableKeyMaterial() {
    preferences.edit().clear().commit()
    val keyStore = KeyStore.getInstance(KEYSTORE_PROVIDER).apply { load(null) }
    if (keyStore.containsAlias(KEY_ALIAS)) keyStore.deleteEntry(KEY_ALIAS)
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
      if (parts.size != 3 || parts[0] != PAYLOAD_VERSION) {
        // An interrupted app upgrade or an older unsupported payload is not a
        // recoverable session. Remove only this logical value so it cannot
        // break every future cold start.
        preferences.edit().remove(key).commit()
        promise.resolve(null)
        return
      }
      val iv = try {
        Base64.decode(parts[1], Base64.NO_WRAP)
      } catch (_: IllegalArgumentException) {
        preferences.edit().remove(key).commit()
        promise.resolve(null)
        return
      }
      val encrypted = try {
        Base64.decode(parts[2], Base64.NO_WRAP)
      } catch (_: IllegalArgumentException) {
        preferences.edit().remove(key).commit()
        promise.resolve(null)
        return
      }
      val cipher = Cipher.getInstance(TRANSFORMATION)
      cipher.init(
        Cipher.DECRYPT_MODE,
        getOrCreateSecretKey(),
        GCMParameterSpec(GCM_TAG_BITS, iv),
      )
      val decrypted = cipher.doFinal(encrypted)
      promise.resolve(String(decrypted, Charsets.UTF_8))
    } catch (error: AEADBadTagException) {
      // A restored preference cannot be decrypted after Android creates a new
      // app key. Remove the unusable value instead of repeatedly failing boot.
      preferences.edit().remove(key).commit()
      promise.resolve(null)
    } catch (error: KeyPermanentlyInvalidatedException) {
      // Screen-lock/biometric changes can invalidate the AndroidKeyStore key.
      // Every ciphertext under that key is then unreadable, so rotate the key
      // once and continue as a guest instead of rejecting every app launch.
      runCatching { resetUnusableKeyMaterial() }
      promise.resolve(null)
    } catch (error: UnrecoverableKeyException) {
      runCatching { resetUnusableKeyMaterial() }
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
