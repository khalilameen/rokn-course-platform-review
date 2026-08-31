import {mapProductionNotification} from '../src/services/notificationMapper';

describe('production notification language boundary', () => {
  it('prefers explicit Arabic copy over generic and English fields', () => {
    const notification = mapProductionNotification({
      id: 7,
      notification_type: 'wallet_credit',
      title: 'Generic title',
      title_en: 'English title',
      title_ar: 'عنوان عربي',
      message: 'Generic message',
      message_en: 'English message',
      message_ar: 'رسالة عربية',
    });

    expect(notification.title).toBe('عنوان عربي');
    expect(notification.description).toBe('رسالة عربية');
    expect(notification.tone).toBe('coins');
    expect(notification.kind).toBe('coin_reward');
    expect(notification.actionLabel).toBe('افتح المحفظة');
  });

  it('falls back to generic fields when Arabic copy is absent', () => {
    const notification = mapProductionNotification({
      id: 'n-1',
      title: 'Fallback title',
      message: 'Fallback message',
    });

    expect(notification.title).toBe('Fallback title');
    expect(notification.description).toBe('Fallback message');
  });

  it('maps a rich course campaign without changing its destination', () => {
    const notification = mapProductionNotification({
      id: 'course-9',
      notification_type: 'enrolled_stalled',
      title_ar: 'الكورس ده عندك بالفعل',
      message_ar: 'كمّل من مكانك',
      link: 'rokn://course/9/watch',
      course_image: 'https://cdn.rokn.app/course-9.jpg',
      action_label_ar: 'كمّل الكورس',
    });

    expect(notification.kind).toBe('continue_course');
    expect(notification.imageUrl).toBe('https://cdn.rokn.app/course-9.jpg');
    expect(notification.actionLabel).toBe('كمّل الكورس');
    expect(notification.link).toBe('rokn://course/9/watch');
  });

  it('opens a recommended course details page when the dashboard sends only its id', () => {
    const notification = mapProductionNotification({
      id: 'recommended-12',
      notification_type: 'course_recommendation',
      title_ar: 'ده ممكن يناسبك',
      message_ar: 'شوف التفاصيل وخد قرارك',
      course_id: 'course-12',
    });

    expect(notification.link).toBe('rokn://course/course-12');
  });

  it('keeps continuation campaigns pointed at the player', () => {
    const notification = mapProductionNotification({
      id: 'continue-12',
      notification_type: 'continue_course',
      course_id: '12',
    });

    expect(notification.link).toBe('rokn://course/12/watch');
  });

  it('understands current backend event names and upgrades a learning nudge to the player', () => {
    const reward = mapProductionNotification({
      id: 'welcome',
      notification_type: 'coins_claimed',
      notifiable_type: 'App\\Models\\CoinEarningMethod',
      notifiable_id: 4,
    });
    const nudge = mapProductionNotification({
      id: 'nudge',
      notification_type: 'learning_nudge',
      link: '/courses/72',
      notifiable_type: 'App\\Models\\Course',
      notifiable_id: 72,
    });

    expect(reward.kind).toBe('coin_reward');
    expect(reward.tone).toBe('coins');
    expect(reward.link).toBe('rokn://wallet');
    expect(nudge.kind).toBe('continue_course');
    expect(nudge.courseId).toBe('72');
    expect(nudge.link).toBe('rokn://course/72/watch');
  });

  it('never emits an unusable fallback from an unsafe course id', () => {
    const notification = mapProductionNotification({
      id: 'unsafe',
      notification_type: 'course_recommendation',
      course_id: 'course 12',
    });

    expect(notification.courseId).toBeUndefined();
    expect(notification.link).toBeUndefined();
  });
});
