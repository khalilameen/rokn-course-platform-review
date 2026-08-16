package com.rokn.reminders

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

class ReminderBootReceiver : BroadcastReceiver() {
  override fun onReceive(context: Context, intent: Intent) {
    if (
      intent.action != Intent.ACTION_BOOT_COMPLETED &&
      intent.action != Intent.ACTION_MY_PACKAGE_REPLACED
    ) return

    val now = System.currentTimeMillis()
    val alarmManager = context.getSystemService(Context.ALARM_SERVICE) as AlarmManager
    ReminderScheduleStore.all(context).forEach { reminder ->
      if (reminder.triggerAt <= now) {
        ReminderScheduleStore.remove(context, reminder.id)
        return@forEach
      }
      val pendingIntent = PendingIntent.getBroadcast(
        context,
        reminder.id,
        Intent(context, ReminderReceiver::class.java).apply {
          putExtra(ReminderReceiver.EXTRA_ID, reminder.id)
          putExtra(ReminderReceiver.EXTRA_TITLE, reminder.title)
          putExtra(ReminderReceiver.EXTRA_BODY, reminder.body)
          putExtra(ReminderReceiver.EXTRA_COURSE_ID, reminder.courseId)
          putExtra(ReminderReceiver.EXTRA_LINK, reminder.link)
          putExtra(ReminderReceiver.EXTRA_KIND, reminder.kind)
          putExtra(ReminderReceiver.EXTRA_IMAGE_URL, reminder.imageUrl)
          putExtra(ReminderReceiver.EXTRA_ACTION_LABEL, reminder.actionLabel)
        },
        PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
      )
      alarmManager.setWindow(
        AlarmManager.RTC_WAKEUP,
        reminder.triggerAt,
        10 * 60 * 1_000L,
        pendingIntent,
      )
    }
  }
}
