jest.mock('react-native-fs', () => ({
  CachesDirectoryPath: '/cache',
  copyFile: jest.fn(),
  mkdir: jest.fn(),
  unlink: jest.fn(),
}));

jest.mock('../src/constants/api', () => ({
  publicRequest: {
    get: jest.fn(),
    post: jest.fn(),
  },
}));

jest.mock('../src/services/roknApi', () => ({
  hasSession: jest.fn(),
}));

jest.mock('../src/config/runtime', () => ({
  isLocalDemoId: jest.fn(() => false),
  LOCAL_DEMO_ENABLED: false,
}));

import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  flushPendingPlaybackPositions,
  mapCoursePayload,
  retryPendingPlaybackPositions,
  savePlaybackPosition,
  WATCH_HISTORY_ENABLED_KEY,
  unlockAfterProject,
} from '../src/components/VideoPlayer/courseLearningApi';
import type {CourseLearningData} from '../src/components/VideoPlayer/types';
import {publicRequest} from '../src/constants/api';
import {accountScopedStorageKey} from '../src/constants/helpers';
import {hasSession} from '../src/services/roknApi';
import {buildAccessibleFeed} from '../src/screens/reels/presentation';

const apiPost = publicRequest.post as jest.MockedFunction<
  typeof publicRequest.post
>;
const sessionAvailable = hasSession as jest.MockedFunction<typeof hasSession>;

describe('course progression boundaries', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
    sessionAvailable.mockResolvedValue(true);
    apiPost.mockResolvedValue({} as any);
  });

  it('keeps locked lesson metadata when its media URL is withheld', () => {
    const course = mapCoursePayload({
      data: {
        course: {
          id: 'course-1',
          title: 'Course',
          modules: [
            {
              id: 'module-1',
              title: 'Module',
              order: 1,
              sections: [
                {
                  id: 'section-1',
                  type: 'lesson',
                  order: 1,
                  title: 'Available lesson',
                  content: {
                    id: 'lesson-1',
                    video_url: 'https://cdn.example/lesson-1.m3u8',
                  },
                },
                {
                  id: 'section-2',
                  type: 'lesson',
                  order: 2,
                  title: 'Locked lesson title',
                  is_locked: true,
                  content: {id: 'lesson-2'},
                },
                {
                  id: 'section-3',
                  type: 'lesson',
                  order: 3,
                  title: 'Later lesson',
                  content: {
                    id: 'lesson-3',
                    video_url: 'https://cdn.example/lesson-3.m3u8',
                  },
                },
                {
                  id: 'project-1',
                  type: 'project',
                  order: 4,
                  title: 'Crossing project',
                  content: {id: 'project-content-1'},
                },
              ],
            },
          ],
        },
      },
    });

    expect(course).not.toBeNull();
    expect(course?.totalReels).toBe(3);
    expect(course?.modules[0].reels).toHaveLength(3);
    expect(course?.modules[0].reels[1]).toMatchObject({
      title: 'Locked lesson title',
      videoUrl: '',
      isLocked: true,
      isPreview: false,
    });
    expect(course?.modules[0].reels[2]).toMatchObject({
      title: 'Later lesson',
      isLocked: true,
    });
    expect(course?.modules[0].project?.title).toBe('Crossing project');
  });

  it('shows progression projects but never turns a purchase boundary into one', () => {
    const course = mapCoursePayload({
      data: {
        course: {
          id: 'course-project-boundaries',
          title: 'Course',
          modules: [
            {
              id: 'module-1',
              title: 'Module',
              sections: [
                {
                  id: 'lesson-section',
                  type: 'lesson',
                  content: {
                    id: 'lesson-1',
                    video_url: 'https://cdn.example/lesson.m3u8',
                  },
                },
                {
                  id: 'progression-project',
                  content_id: 'project-1',
                  type: 'project',
                  title: 'مشروع العبور',
                  is_locked: true,
                  lock_reason: 'previous_section_incomplete',
                },
                {
                  id: 'purchase-project',
                  content_id: 'project-2',
                  type: 'project',
                  title: 'ليس مشروعًا متاحًا بعد',
                  is_locked: true,
                  lock_reason: 'course_purchase_required',
                },
              ],
            },
          ],
        },
      },
    });

    expect(course?.modules[0].projects).toEqual([
      expect.objectContaining({
        sectionId: 'progression-project',
        title: 'مشروع العبور',
        isLocked: true,
        lockReason: 'previous_section_incomplete',
      }),
    ]);
  });

  it('maps course quizzes as visible progression gates', () => {
    const course = mapCoursePayload({
      data: {
        course: {
          id: 'course-with-quiz',
          title: 'Course',
          modules: [{
            id: 'module-1',
            title: 'Module',
            sections: [
              {
                id: 'lesson-section',
                type: 'lesson',
                is_completed: true,
                content: {id: 'lesson-1', video_url: 'https://cdn.example/1.m3u8'},
              },
              {
                id: 'quiz-section',
                type: 'quiz',
                title: 'اختبار الوحدة',
                content: {id: 'quiz-1', time_minutes: 5, is_passed: false},
              },
            ],
          }],
        },
      },
    });

    expect(course?.modules[0].quizzes).toEqual([expect.objectContaining({
      id: 'quiz-1',
      sectionId: 'quiz-section',
      title: 'اختبار الوحدة',
      timeMinutes: 5,
      passed: false,
    })]);
    expect(buildAccessibleFeed(course!).map(item => item.key)).toEqual([
      'reel-lesson-1',
      'quiz-quiz-1',
    ]);
  });

  it('maps the dashboard attachment discovery contract without inventing files', () => {
    const course = mapCoursePayload({
      data: {
        course: {
          id: 'course-attachments',
          title: 'Course',
          attachment_prompt: {
            enabled: true,
            at_seconds: 17,
            title: 'ملفات التطبيق',
            body: 'حمّل القالب قبل تنفيذ الخطوة.',
            button_text: 'افتح الملفات',
          },
          modules: [
            {
              id: 'module-1',
              title: 'Module',
              order: 1,
              attachments: [
                {
                  id: 'attachment-1',
                  title: 'قالب العمل',
                  url: 'https://cdn.example/template.pdf',
                  platform: 'computer',
                },
              ],
              sections: [
                {
                  id: 'section-1',
                  type: 'lesson',
                  content: {
                    id: 'lesson-1',
                    video_url: 'https://cdn.example/lesson.m3u8',
                  },
                },
              ],
            },
          ],
        },
      },
    });

    expect(course?.attachmentPrompt).toEqual({
      enabled: true,
      atSeconds: 17,
      title: 'ملفات التطبيق',
      body: 'حمّل القالب قبل تنفيذ الخطوة.',
      buttonText: 'افتح الملفات',
      frequency: 'once_per_module',
    });
    expect(course?.modules[0].attachments).toHaveLength(1);
    expect(course?.modules[0].attachments[0].platform).toBe('computer');
  });

  it('keeps reel titles and captions separate and allows theory modules without projects', () => {
    const course = mapCoursePayload({
      data: {
        course: {
          id: 'theory-course',
          title: 'كورس نظري',
          modules: [
            {
              id: 'module-1',
              title: 'الوحدة الأولى',
              order: 1,
              sections: [
                {
                  id: 'section-1',
                  type: 'lesson',
                  order: 1,
                  title: 'العنوان الظاهر على الريل',
                  content: {
                    id: 'lesson-1',
                    title: 'عنوان التخزين الداخلي',
                    description: 'هذا هو الكابشن المستقل أسفل الفيديو',
                    video_url: 'https://cdn.example/lesson-1.m3u8',
                  },
                },
              ],
            },
            {
              id: 'module-2',
              title: 'الوحدة الثانية',
              order: 2,
              sections: [
                {
                  id: 'section-2',
                  type: 'lesson',
                  order: 1,
                  title: 'مقطع بلا مشروع قبله',
                  content: {
                    id: 'lesson-2',
                    video_url: 'https://cdn.example/lesson-2.m3u8',
                  },
                },
              ],
            },
          ],
        },
      },
    });

    expect(course?.modules[0].reels[0]).toMatchObject({
      title: 'العنوان الظاهر على الريل',
      caption: 'هذا هو الكابشن المستقل أسفل الفيديو',
    });
    expect(course?.modules[0].project).toBeUndefined();
    expect(course?.modules[1].project).toBeUndefined();
    expect(buildAccessibleFeed(course!)).toHaveLength(2);
  });

  it('does not expose project AI output when the enrolled plan did not grant it', () => {
    const course = mapCoursePayload({
      data: {
        access_type: 'free',
        chat_available: false,
        course: {
          id: 'free-course',
          title: 'Free course',
          modules: [{
            id: 'module-1',
            title: 'Module',
            sections: [
              {
                id: 'lesson-section',
                type: 'lesson',
                content: {id: 'lesson-1', video_url: 'https://cdn.example/1.m3u8'},
              },
              {
                id: 'project-section',
                type: 'project',
                content: {
                  id: 'project-1',
                  project_feedback: {
                    level: 'pass_only',
                    report_enabled: false,
                    output_enabled: false,
                  },
                  feedback_thread: {
                    id: 'thread-that-must-not-leak',
                    feedback_level: 'report',
                    can_reply: true,
                    messages: [],
                  },
                },
              },
            ],
          }],
        },
      },
    });

    expect(course?.chatAvailable).toBe(false);
    expect(course?.modules[0].project).toMatchObject({
      feedbackLevel: 'pass_only',
      outputEnabled: false,
      reportEnabled: false,
      feedbackThread: undefined,
    });
  });

  it('never unlocks the next module for a reviewing project', () => {
    const course = progressionFixture();
    const next = unlockAfterProject(course, 'project-1', 'reviewing');

    expect(next.modules[0].project?.status).toBe('reviewing');
    expect(next.modules[1].isLocked).toBe(true);
    expect(next.modules[1].reels[0].isLocked).toBe(true);
  });

  it('keeps media without a URL locked after a confirmed pass', () => {
    const course = progressionFixture();
    course.modules[1].reels[0].videoUrl = '';

    const next = unlockAfterProject(course, 'project-1', 'passed');

    expect(next.modules[0].project?.status).toBe('passed');
    expect(next.modules[1].isLocked).toBe(false);
    expect(next.modules[1].reels[0].isLocked).toBe(true);
    expect(next.modules[1].reels[1].isLocked).toBe(true);
  });

  it('keeps resume local and batches remote watch-history samples', async () => {
    await savePlaybackPosition('course-1', 'reel-1', 15, '101', 120);
    await savePlaybackPosition('course-1', 'reel-1', 25, '101', 120);

    expect(apiPost).toHaveBeenCalledTimes(1);
    await flushPendingPlaybackPositions();
    expect(apiPost).toHaveBeenCalledTimes(2);
    expect(apiPost).toHaveBeenLastCalledWith('user/watch-history', {
      lesson_id: 101,
      position_seconds: 25,
      duration_seconds: 120,
      is_completed: false,
      event_type: 'heartbeat',
    });
  });

  it('keeps required learning evidence flowing when optional history is off', async () => {
    await AsyncStorage.setItem(
      await accountScopedStorageKey(WATCH_HISTORY_ENABLED_KEY),
      JSON.stringify(false),
    );

    await savePlaybackPosition('course-2', 'reel-2', 20, '202', 90);
    await flushPendingPlaybackPositions();

    expect(apiPost).toHaveBeenCalledWith('user/watch-history', {
      lesson_id: 202,
      position_seconds: 20,
      duration_seconds: 90,
      is_completed: false,
      event_type: 'heartbeat',
    });
  });

  it('durably retries the latest evidence after a network failure', async () => {
    apiPost.mockRejectedValueOnce(new Error('offline'));
    await savePlaybackPosition('course-3', 'reel-3', 12, '303', 60);

    expect(
      (await AsyncStorage.getAllKeys()).some(key =>
        key.startsWith('@rokn/watch-evidence/v1:'),
      ),
    ).toBe(true);

    apiPost.mockResolvedValue({} as any);
    await retryPendingPlaybackPositions();

    expect(apiPost).toHaveBeenLastCalledWith('user/watch-history', {
      lesson_id: 303,
      position_seconds: 12,
      duration_seconds: 60,
      is_completed: false,
      event_type: 'heartbeat',
    });
    expect(
      (await AsyncStorage.getAllKeys()).some(key =>
        key.startsWith('@rokn/watch-evidence/v1:'),
      ),
    ).toBe(false);
  });
});

const progressionFixture = (): CourseLearningData => ({
  id: 'course-1',
  title: 'Course',
  totalReels: 3,
  modules: [
    {
      id: 'module-1',
      title: 'Module one',
      order: 1,
      isLocked: false,
      attachments: [],
      reels: [reel('lesson-1', 'module-1', false)],
      project: {
        id: 'project-1',
        sectionId: 'project-section-1',
        moduleId: 'module-1',
        title: 'Project',
        requirements: 'Do the work',
        status: 'not_submitted',
        isGraduationProject: false,
        attachments: [],
      },
    },
    {
      id: 'module-2',
      title: 'Module two',
      order: 2,
      isLocked: true,
      attachments: [],
      reels: [
        reel('lesson-2', 'module-2', true),
        reel('lesson-3', 'module-2', true),
      ],
    },
  ],
});

const reel = (id: string, moduleId: string, isLocked: boolean) => ({
  id,
  lessonId: id,
  sectionId: `section-${id}`,
  moduleId,
  title: id,
  caption: '',
  videoUrl: `https://cdn.example/${id}.m3u8`,
  availableQualities: ['auto' as const],
  isPreview: false,
  isLocked,
  isCompleted: false,
  reelNumber: Number(id.split('-')[1]),
});
