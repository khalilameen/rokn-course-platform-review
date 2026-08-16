# Rokn smart-notification contract

The mobile app accepts one final, learner-specific payload. Audience selection,
frequency caps and scheduling belong to the backend/dashboard so they remain
consistent across devices.

## Payload

```json
{
  "notification_type": "continue_course",
  "title_ar": "الكورس ده عندك بالفعل",
  "message_ar": "ارجع كمّل من آخر خطوة وقفت عندها",
  "link": "rokn://course/123/watch",
  "course_id": "123",
  "image_url": "https://cdn.rokn.app/courses/123/cover.jpg",
  "action_label_ar": "كمّل الكورس",
  "campaign_id": "stalled-learner-72h"
}
```

Supported `notification_type` values:

- `learning_reminder`, `streak_reminder`, `continue_course`
- `course_recommendation`, `new_course`
- `coin_reward`, `coin_offer`
- `project_update`, `certificate_ready`, `account_update`

The mobile mapper also accepts the current transactional backend names without
requiring a database migration: `course_enrolled`, `coins_claimed`,
`package_purchased`, `course_completed`, and `learning_nudge`. New dashboard
campaigns should use the canonical names above; existing rows remain valid.

For Android FCM, send `channel_id` as `rokn-learning`, `rokn-offers`, or
`rokn-updates`. Put the same fields in `data` so tapping a collapsed or expanded
notification always opens the same destination. Course artwork and the Rokn
coin artwork must be HTTPS images; no image is persisted by the app.

`image_url`, `action_label_ar`, and `course_id` are optional response/data
fields. Adding them to the API resource and FCM data payload is a serializer
change only; it must not add columns or duplicate notification rows. Until a
sender includes them, the app derives a safe course destination from the
existing `notifiable_type`/`notifiable_id` and uses the cached catalogue cover
or a branded fallback.

## Dashboard campaign form

Required controls: type, Arabic title, Arabic body, image preview, CTA label,
deep link, audience, send time, expiry and a test-send button. A course picker
must fill `course_id`, cover and link together to prevent mismatched campaigns.

Audience presets:

- Enrolled but stopped: owns the course, unfinished, inactive for 24/72 hours.
- Streak at risk: learned on previous days but has not learned today.
- New course: opted into marketing and has not enrolled in that course.
- Recommendation: opted into marketing, related to a viewed/enrolled category,
  and does not already own the suggested course.
- Coin offer: opted into marketing and eligible for the exact offer.
- Transactional: project result, certificate or credited coins; never marketing.

## Non-negotiable delivery rules

- Quiet hours: 22:00–09:00 in the learner's local time.
- At most one learning reminder per day.
- At most one promotional notification every 72 hours.
- Transactional notifications are immediate and do not consume the promo cap.
- Dedupe by user + campaign + course/event; retries must not create duplicates.
- Never suggest buying or enrolling in a course the learner already owns;
  use `continue_course` instead.
- Stop learning reminders immediately after course completion or opt-out.
- Record sent, opened and destination IDs so copy can be improved using real
  behaviour rather than sending more notifications.
