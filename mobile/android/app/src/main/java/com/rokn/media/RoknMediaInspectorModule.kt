package com.rokn.media

import android.graphics.BitmapFactory
import android.net.Uri
import androidx.core.graphics.get
import androidx.core.net.toUri
import com.facebook.react.bridge.Arguments
import com.facebook.react.bridge.Promise
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.bridge.ReactContextBaseJavaModule
import com.facebook.react.bridge.ReactMethod
import java.io.File
import java.io.FileInputStream
import java.io.InputStream
import kotlin.math.sqrt

class RoknMediaInspectorModule(
  private val reactContext: ReactApplicationContext,
) : ReactContextBaseJavaModule(reactContext) {
  override fun getName(): String = "RoknMediaInspector"

  @ReactMethod
  fun inspect(uriValue: String, promise: Promise) {
    try {
      val uri = uriValue.toUri()
      val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
      open(uri)?.use { BitmapFactory.decodeStream(it, null, bounds) }
      if (bounds.outWidth <= 0 || bounds.outHeight <= 0) {
        promise.reject("UNREADABLE_IMAGE", "The selected image could not be read")
        return
      }

      var sampleSize = 1
      while (bounds.outWidth / sampleSize > MAX_EDGE || bounds.outHeight / sampleSize > MAX_EDGE) {
        sampleSize *= 2
      }
      val options = BitmapFactory.Options().apply {
        inSampleSize = sampleSize
        inPreferredConfig = android.graphics.Bitmap.Config.RGB_565
      }
      val bitmap = open(uri)?.use { BitmapFactory.decodeStream(it, null, options) }
      if (bitmap == null) {
        promise.reject("UNREADABLE_IMAGE", "The selected image could not be decoded")
        return
      }

      try {
        var count = 0
        var sum = 0.0
        var sumSquares = 0.0
        var minimum = 255.0
        var maximum = 0.0
        for (row in 0 until GRID_SIZE) {
          val y = ((row + 0.5) * bitmap.height / GRID_SIZE)
            .toInt().coerceIn(0, bitmap.height - 1)
          for (column in 0 until GRID_SIZE) {
            val x = ((column + 0.5) * bitmap.width / GRID_SIZE)
              .toInt().coerceIn(0, bitmap.width - 1)
            val color = bitmap[x, y]
            val red = (color shr 16) and 0xFF
            val green = (color shr 8) and 0xFF
            val blue = color and 0xFF
            val luminance = 0.2126 * red + 0.7152 * green + 0.0722 * blue
            count += 1
            sum += luminance
            sumSquares += luminance * luminance
            minimum = minOf(minimum, luminance)
            maximum = maxOf(maximum, luminance)
          }
        }
        val average = sum / count
        val variance = (sumSquares / count - average * average).coerceAtLeast(0.0)
        val standardDeviation = sqrt(variance)
        // Reject only an almost-uniform near-black frame. Dark interfaces and photos
        // still pass as soon as they contain meaningful tonal variation.
        val isBlank = average < 14.0 && standardDeviation < 5.0 && (maximum - minimum) < 18.0
        promise.resolve(
          Arguments.createMap().apply {
            putBoolean("isBlank", isBlank)
            putDouble("averageLuminance", average)
            putDouble("standardDeviation", standardDeviation)
            putInt("sampledPixels", count)
          },
        )
      } finally {
        bitmap.recycle()
      }
    } catch (error: Exception) {
      promise.reject("INSPECTION_FAILED", "The selected image could not be inspected", error)
    }
  }

  private fun open(uri: Uri): InputStream? = when (uri.scheme?.lowercase()) {
    "content", "android.resource" -> reactContext.contentResolver.openInputStream(uri)
    "file" -> uri.path?.let { FileInputStream(File(it)) }
    null, "" -> FileInputStream(File(uri.toString()))
    else -> null
  }

  companion object {
    private const val MAX_EDGE = 256
    private const val GRID_SIZE = 12
  }
}
