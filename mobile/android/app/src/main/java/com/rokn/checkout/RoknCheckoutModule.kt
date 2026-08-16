package com.rokn.checkout

import android.app.Activity
import android.content.Intent
import com.facebook.react.bridge.ActivityEventListener
import com.facebook.react.bridge.BaseActivityEventListener
import com.facebook.react.bridge.Promise
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.bridge.ReactContextBaseJavaModule
import com.facebook.react.bridge.ReactMethod

class RoknCheckoutModule(
  private val reactContext: ReactApplicationContext,
) : ReactContextBaseJavaModule(reactContext) {
  private var checkoutPromise: Promise? = null

  private val activityListener: ActivityEventListener =
    object : BaseActivityEventListener() {
      override fun onActivityResult(
        activity: Activity,
        requestCode: Int,
        resultCode: Int,
        data: Intent?,
      ) {
        if (requestCode != CHECKOUT_REQUEST) return

        val promise = checkoutPromise ?: return
        checkoutPromise = null
        if (resultCode == Activity.RESULT_OK) {
          promise.resolve(data?.getStringExtra(CheckoutActivity.RESULT_URL) ?: "")
        } else {
          promise.reject("CHECKOUT_CANCELLED", "Checkout was closed before completion")
        }
      }
    }

  init {
    reactContext.addActivityEventListener(activityListener)
  }

  override fun getName(): String = "RoknCheckout"

  @ReactMethod
  fun open(url: String, promise: Promise) {
    val activity = reactApplicationContext.currentActivity
    if (activity == null) {
      promise.reject("NO_ACTIVITY", "The checkout cannot be opened right now")
      return
    }
    if (checkoutPromise != null) {
      promise.reject("CHECKOUT_ACTIVE", "Another checkout is already open")
      return
    }

    checkoutPromise = promise
    val intent = Intent(activity, CheckoutActivity::class.java).apply {
      putExtra(CheckoutActivity.EXTRA_URL, url)
    }
    activity.startActivityForResult(intent, CHECKOUT_REQUEST)
  }

  companion object {
    private const val CHECKOUT_REQUEST = 7301
  }
}
