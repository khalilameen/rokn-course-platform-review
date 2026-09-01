package com.rokn.diagnostics

import android.app.ActivityManager
import android.app.Application
import android.content.Context
import android.os.Build
import androidx.core.content.edit
import org.json.JSONObject
import java.security.MessageDigest
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone
import java.util.UUID

object RoknDiagnosticsStore {
  private const val PREFERENCES = "rokn_operational_diagnostics"
  private const val PENDING_EVENT = "pending_exit_event"
  private const val LAST_EXIT_TIMESTAMP = "last_exit_timestamp"

  fun install(application: Application) {
    captureHistoricalExit(application)
    val previous = Thread.getDefaultUncaughtExceptionHandler()
    Thread.setDefaultUncaughtExceptionHandler { thread, throwable ->
      val firstFrame = throwable.stackTrace.firstOrNull()
      val fingerprint = sha256(
        listOfNotNull(
          throwable.javaClass.name,
          firstFrame?.className,
          firstFrame?.methodName,
        ).joinToString("|"),
      )
      persist(
        application,
        errorCode = "NATIVE_CRASH",
        fingerprint = fingerprint,
        occurredAtMillis = System.currentTimeMillis(),
      )
      previous?.uncaughtException(thread, throwable)
    }
  }

  fun consume(application: Application): JSONObject? {
    val preferences = application.getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE)
    val raw = preferences.getString(PENDING_EVENT, null) ?: return null
    return try {
      val event = JSONObject(raw)
      if (event.optString("event_id").isBlank()) {
        event.put("event_id", UUID.randomUUID().toString())
        preferences.edit(commit = true) { putString(PENDING_EVENT, event.toString()) }
      }
      event
    } catch (_: Throwable) {
      preferences.edit(commit = true) { remove(PENDING_EVENT) }
      null
    }
  }

  fun acknowledge(application: Application, eventId: String): Boolean {
    val preferences = application.getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE)
    val raw = preferences.getString(PENDING_EVENT, null) ?: return true
    return try {
      val currentId = JSONObject(raw).optString("event_id")
      if (currentId.isBlank() || currentId != eventId) return false
      preferences.edit(commit = true) { remove(PENDING_EVENT) }
      true
    } catch (_: Throwable) {
      false
    }
  }

  private fun captureHistoricalExit(application: Application) {
    if (Build.VERSION.SDK_INT < Build.VERSION_CODES.R) return
    try {
      val preferences = application.getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE)
      val lastSeen = preferences.getLong(LAST_EXIT_TIMESTAMP, 0L)
      val activityManager = application.getSystemService(ActivityManager::class.java)
      val exit = activityManager
        .getHistoricalProcessExitReasons(application.packageName, 0, 8)
        .filter { it.timestamp > lastSeen }
        .maxByOrNull { it.timestamp }
        ?: return
      // Advance the cursor before emitting the event to avoid replay after restart.
      preferences.edit(commit = true) {
        putLong(LAST_EXIT_TIMESTAMP, exit.timestamp)
      }
      // The synchronous crash handler keeps a more precise fingerprint. Do
      // not overwrite it with the coarser historical-exit classification.
      if (preferences.contains(PENDING_EVENT)) return
      val errorCode = when (exit.reason) {
        android.app.ApplicationExitInfo.REASON_ANR -> "NATIVE_ANR"
        android.app.ApplicationExitInfo.REASON_CRASH,
        android.app.ApplicationExitInfo.REASON_CRASH_NATIVE -> "NATIVE_CRASH"
        else -> null
      } ?: return
      persist(
        application,
        errorCode = errorCode,
        fingerprint = sha256("android|$errorCode|${exit.importance}"),
        occurredAtMillis = exit.timestamp,
      )
    } catch (_: Throwable) {
      // Diagnostics must never make application startup less reliable.
    }
  }

  private fun persist(
    application: Application,
    errorCode: String,
    fingerprint: String,
    occurredAtMillis: Long,
  ) {
    try {
      val payload = JSONObject()
        .put("event_id", UUID.randomUUID().toString())
        .put("error_code", errorCode)
        .put("error_fingerprint", fingerprint)
        .put("occurred_at", isoTimestamp(occurredAtMillis))
      // A crash handler may terminate immediately; this write must be synchronous.
      application
        .getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE)
        .edit(commit = true) {
          putString(PENDING_EVENT, payload.toString())
        }
    } catch (_: Throwable) {
      // The prior crash handler must still run even if storage is unavailable.
    }
  }

  private fun sha256(value: String): String =
    MessageDigest
      .getInstance("SHA-256")
      .digest(value.toByteArray(Charsets.UTF_8))
      .joinToString("") { byte -> "%02x".format(byte) }

  private fun isoTimestamp(value: Long): String =
    SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss.SSS'Z'", Locale.US).apply {
      timeZone = TimeZone.getTimeZone("UTC")
    }.format(Date(value))
}
