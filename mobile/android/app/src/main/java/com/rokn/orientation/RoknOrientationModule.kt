package com.rokn.orientation

import android.content.pm.ActivityInfo
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.bridge.ReactContextBaseJavaModule
import com.facebook.react.bridge.ReactMethod

class RoknOrientationModule(
  reactContext: ReactApplicationContext,
) : ReactContextBaseJavaModule(reactContext) {
  override fun getName(): String = "RoknOrientation"

  @ReactMethod
  fun lockPortrait() {
    reactApplicationContext.currentActivity?.requestedOrientation =
      ActivityInfo.SCREEN_ORIENTATION_PORTRAIT
  }

  @ReactMethod
  fun unlock() {
    reactApplicationContext.currentActivity?.requestedOrientation =
      ActivityInfo.SCREEN_ORIENTATION_UNSPECIFIED
  }
}
