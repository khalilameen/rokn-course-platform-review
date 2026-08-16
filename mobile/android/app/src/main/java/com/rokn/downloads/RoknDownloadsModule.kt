package com.rokn.downloads

import android.app.DownloadManager
import android.content.Context
import android.os.Build
import android.os.Environment
import android.webkit.MimeTypeMap
import androidx.core.net.toUri
import com.facebook.react.bridge.Promise
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.bridge.ReactContextBaseJavaModule
import com.facebook.react.bridge.ReactMethod
import com.rokn.BuildConfig

class RoknDownloadsModule(
  private val reactContext: ReactApplicationContext,
) : ReactContextBaseJavaModule(reactContext) {
  override fun getName(): String = "RoknDownloads"

  @ReactMethod
  fun enqueue(
    url: String,
    title: String,
    fileName: String,
    promise: Promise,
  ) {
    try {
      val uri = url.toUri()
      val scheme = uri.scheme.orEmpty()
      val allowed = scheme.equals("https", ignoreCase = true) ||
        (BuildConfig.DEBUG && scheme.equals("http", ignoreCase = true))
      if (!allowed || uri.host.isNullOrBlank()) {
        promise.reject("INVALID_DOWNLOAD_URL", "Only secure download links are supported")
        return
      }

      val safeName = sanitizeFileName(fileName)
      val request = DownloadManager.Request(uri)
        .setTitle(title.trim().take(80).ifBlank { safeName })
        .setDescription("جاري تنزيل مرفق الكورس")
        .setNotificationVisibility(
          DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED,
        )
        .setAllowedOverMetered(true)
        .setAllowedOverRoaming(true)

      val extension = MimeTypeMap.getFileExtensionFromUrl(uri.toString())
        .ifBlank { safeName.substringAfterLast('.', "") }
        .lowercase()
      MimeTypeMap.getSingleton().getMimeTypeFromExtension(extension)?.let(request::setMimeType)

      if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
        request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, safeName)
      } else {
        // App-specific external storage needs no dangerous permission on Android 7–9.
        request.setDestinationInExternalFilesDir(
          reactContext,
          Environment.DIRECTORY_DOWNLOADS,
          safeName,
        )
      }

      val manager = reactContext.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
      promise.resolve(manager.enqueue(request).toDouble())
    } catch (error: Exception) {
      promise.reject("DOWNLOAD_FAILED", "The download could not be started", error)
    }
  }

  private fun sanitizeFileName(value: String): String {
    val cleaned = value
      .substringAfterLast('/')
      .substringAfterLast('\\')
      .replace(Regex("[^a-zA-Z0-9._\\-\\u0600-\\u06FF]"), "-")
      .trim('.', '-', ' ')
      .take(120)
    return cleaned.ifBlank { "rokn-attachment-${System.currentTimeMillis()}" }
  }
}
