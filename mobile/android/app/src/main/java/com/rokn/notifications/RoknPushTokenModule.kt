package com.rokn.notifications

import com.facebook.react.bridge.Promise
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.bridge.ReactContextBaseJavaModule
import com.facebook.react.bridge.ReactMethod
import com.google.firebase.messaging.FirebaseMessaging

/**
 * Invalidates this installation's FCM token at the SDK boundary.
 *
 * Logging out must remain private even when Rokn's API is unreachable: deleting
 * the local Firebase token makes the token still stored by a remote server
 * unusable, without persisting a bearer token for a later retry.
 */
class RoknPushTokenModule(
  reactContext: ReactApplicationContext,
) : ReactContextBaseJavaModule(reactContext) {
  override fun getName(): String = "RoknPushTokens"

  @ReactMethod
  fun deleteToken(promise: Promise) {
    try {
      FirebaseMessaging.getInstance().deleteToken().addOnCompleteListener { task ->
        if (task.isSuccessful) {
          promise.resolve(true)
        } else {
          promise.reject(
            "FCM_TOKEN_DELETE_FAILED",
            task.exception ?: IllegalStateException("Firebase did not delete the token"),
          )
        }
      }
    } catch (error: Throwable) {
      promise.reject("FCM_TOKEN_DELETE_FAILED", error)
    }
  }
}
