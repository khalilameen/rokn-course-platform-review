package com.rokn.reminders

import android.content.Context
import androidx.core.content.edit

data class StoredReminder(
  val id: Int,
  val title: String,
  val body: String,
  val triggerAt: Long,
  val courseId: String?,
  val link: String?,
  val kind: String,
  val imageUrl: String?,
  val actionLabel: String?,
)

object ReminderScheduleStore {
  private const val PREFS = "rokn_learning_reminders"
  private const val IDS = "active_ids"

  fun save(context: Context, reminder: StoredReminder) {
    val preferences = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
    val ids = preferences.getStringSet(IDS, emptySet()).orEmpty().toMutableSet()
    ids.add(reminder.id.toString())
    preferences.edit {
      putStringSet(IDS, ids)
      putString(key(reminder.id, "title"), reminder.title)
      putString(key(reminder.id, "body"), reminder.body)
      putLong(key(reminder.id, "trigger"), reminder.triggerAt)
      putString(key(reminder.id, "kind"), reminder.kind)
      if (reminder.courseId.isNullOrBlank()) {
        remove(key(reminder.id, "course"))
      } else {
        putString(key(reminder.id, "course"), reminder.courseId)
      }
      if (reminder.link.isNullOrBlank()) remove(key(reminder.id, "link"))
      else putString(key(reminder.id, "link"), reminder.link)
      if (reminder.imageUrl.isNullOrBlank()) remove(key(reminder.id, "image"))
      else putString(key(reminder.id, "image"), reminder.imageUrl)
      if (reminder.actionLabel.isNullOrBlank()) remove(key(reminder.id, "action"))
      else putString(key(reminder.id, "action"), reminder.actionLabel)
    }
  }

  fun remove(context: Context, id: Int) {
    val preferences = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
    val ids = preferences.getStringSet(IDS, emptySet()).orEmpty().toMutableSet()
    ids.remove(id.toString())
    preferences.edit {
      putStringSet(IDS, ids)
      remove(key(id, "title"))
      remove(key(id, "body"))
      remove(key(id, "trigger"))
      remove(key(id, "course"))
      remove(key(id, "link"))
      remove(key(id, "kind"))
      remove(key(id, "image"))
      remove(key(id, "action"))
    }
  }

  fun all(context: Context): List<StoredReminder> {
    val preferences = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
    return preferences.getStringSet(IDS, emptySet()).orEmpty().mapNotNull { rawId ->
      val id = rawId.toIntOrNull() ?: return@mapNotNull null
      val title = preferences.getString(key(id, "title"), null) ?: return@mapNotNull null
      val body = preferences.getString(key(id, "body"), null) ?: return@mapNotNull null
      val triggerAt = preferences.getLong(key(id, "trigger"), 0L)
      if (triggerAt <= 0L) return@mapNotNull null
      StoredReminder(
        id = id,
        title = title,
        body = body,
        triggerAt = triggerAt,
        courseId = preferences.getString(key(id, "course"), null),
        link = preferences.getString(key(id, "link"), null),
        kind = preferences.getString(key(id, "kind"), ReminderReceiver.KIND_LEARNING)
          ?: ReminderReceiver.KIND_LEARNING,
        imageUrl = preferences.getString(key(id, "image"), null),
        actionLabel = preferences.getString(key(id, "action"), null),
      )
    }
  }

  private fun key(id: Int, field: String) = "reminder_${id}_$field"
}
