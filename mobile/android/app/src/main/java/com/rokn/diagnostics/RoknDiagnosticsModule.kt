package com.rokn.diagnostics

import com.facebook.react.bridge.Arguments
import com.facebook.react.bridge.Promise
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.bridge.ReactContextBaseJavaModule
import com.facebook.react.bridge.ReactMethod
import com.rokn.MainApplication

class RoknDiagnosticsModule(
  reactContext: ReactApplicationContext,
) : ReactContextBaseJavaModule(reactContext) {
  override fun getName(): String = "RoknDiagnostics"

  @ReactMethod
  fun consumePendingExitEvent(promise: Promise) {
    try {
      val application = reactApplicationContext.applicationContext as MainApplication
      val event = RoknDiagnosticsStore.consume(application)
      if (event == null) {
        promise.resolve(null)
        return
      }
      val result = Arguments.createMap().apply {
        putString("error_code", event.optString("error_code"))
        putString("error_fingerprint", event.optString("error_fingerprint"))
        putString("occurred_at", event.optString("occurred_at"))
      }
      promise.resolve(result)
    } catch (error: Throwable) {
      promise.reject("NATIVE_DIAGNOSTICS_READ_FAILED", error)
    }
  }
}
