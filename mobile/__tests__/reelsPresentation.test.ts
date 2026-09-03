import {
  buildAccessibleFeed,
  buildPreviewFeed,
  PLAYBACK_PREFERENCE_BITRATE_KBPS,
  resolveReelsFrameWidth,
  markQuizPassed,
  updateProjectStatusOnly,
} from '../src/screens/reels/presentation';
import {
  buildPlaybackEvidence,
  markReelCompleted,
} from '../src/screens/reels/progress';
import type {
  CourseLearningData,
  VideoQuality,
} from '../src/components/VideoPlayer/types';

describe('reels presentation policy', () => {
  it('keeps entitled source-less reels reachable so their manifest can load', () => {
    const course = fixture();
    course.modules[0].reels[1].videoUrl = '';

    expect(buildAccessibleFeed(course).map(item => item.key)).toEqual([
      'reel-reel-1',
      'reel-reel-2',
      'project-project-1',
    ]);

    course.modules[0].reels[1].videoUrl = 'https://cdn.example/2.m3u8';
    expect(buildAccessibleFeed(course).map(item => item.key)).toEqual([
      'reel-reel-1',
      'reel-reel-2',
      'project-project-1',
    ]);
  });

  it('uses marked previews before the numeric fallback', () => {
    const course = fixture();
    course.modules[0].reels[1].isPreview = true;

    const feed = buildPreviewFeed(course, 1);

    expect(feed.map(item => item.key)).toEqual(['reel-reel-2']);
    expect(feed[0].type === 'reel' && feed[0].reel.isLocked).toBe(false);
  });

  it('updates only the reviewed project status', () => {
    const course = fixture();
    const next = updateProjectStatusOnly(course, 'project-1', 'reviewing');

    expect(next.modules[0].project?.status).toBe('reviewing');
    expect(next.modules[1]).toEqual(course.modules[1]);
    expect(course.modules[0].project?.status).toBe('not_submitted');
  });

  it('stops at an unfinished quiz and exposes the project only after passing', () => {
    const course = fixture();
    course.modules[0].quizzes = [{
      id: 'quiz-1',
      sectionId: 'quiz-section-1',
      moduleId: 'module-1',
      title: 'Quiz',
      isLocked: false,
      passed: false,
    }];

    expect(buildAccessibleFeed(course).map(item => item.key)).toEqual([
      'reel-reel-1',
      'reel-reel-2',
      'quiz-quiz-1',
    ]);

    const passed = markQuizPassed(course, 'quiz-1');
    expect(buildAccessibleFeed(passed).map(item => item.key)).toEqual([
      'reel-reel-1',
      'reel-reel-2',
      'quiz-quiz-1',
      'project-project-1',
    ]);
  });

  it('keeps phone width and caps wide layouts by video aspect', () => {
    expect(resolveReelsFrameWidth({width: 390, height: 844})).toBe(390);
    expect(resolveReelsFrameWidth({width: 1024, height: 800})).toBe(500);
    expect(resolveReelsFrameWidth({width: 0, height: 800})).toBe(0);
    expect(PLAYBACK_PREFERENCE_BITRATE_KBPS['360p']).toBe(750);
  });

  it('keeps playback evidence mapping identical across progress paths', () => {
    expect(
      buildPlaybackEvidence(
        {playbackSessionId: 'session-1'},
        {
          effectiveQuality: '720p',
          effectiveBitrateKbps: 2800,
          recoveryCount: 2,
          bufferCount: 3,
          bufferDurationMs: 1200,
          startupLatencyMs: 450,
          diagnostics: {stage: 'playing'},
        },
        1.25,
      ),
    ).toEqual({
      playbackSessionId: 'session-1',
      effectiveQuality: '720p',
      effectiveBitrateKbps: 2800,
      playbackRate: 1.25,
      recoveryCount: 2,
      bufferCount: 3,
      bufferDurationMs: 1200,
      startupLatencyMs: 450,
      diagnostics: {stage: 'playing'},
    });
  });

  it('marks the reel complete and unlocks only its immediate successor', () => {
    const course = fixture();
    course.modules[0].reels[0].isCompleted = false;
    course.modules[0].reels[1].isLocked = true;

    const next = markReelCompleted(course, course.modules[0].reels[0]);

    expect(next.modules[0].reels[0].isCompleted).toBe(true);
    expect(next.modules[0].reels[1].isLocked).toBe(false);
    expect(course.modules[0].reels[0].isCompleted).toBe(false);
  });
});

const fixture = (): CourseLearningData => ({
  id: 'course-1',
  title: 'Course',
  totalReels: 3,
  modules: [
    {
      id: 'module-1',
      title: 'Module 1',
      order: 1,
      isLocked: false,
      attachments: [],
      reels: [
        reel('reel-1', 'module-1', true),
        reel('reel-2', 'module-1', true),
      ],
      project: {
        id: 'project-1',
        sectionId: 'project-section-1',
        moduleId: 'module-1',
        title: 'Project',
        requirements: 'Ship it',
        status: 'not_submitted',
        isGraduationProject: false,
        attachments: [],
      },
    },
    {
      id: 'module-2',
      title: 'Module 2',
      order: 2,
      isLocked: true,
      attachments: [],
      reels: [reel('reel-3', 'module-2', false)],
    },
  ],
});

const reel = (id: string, moduleId: string, isCompleted: boolean) => ({
  id,
  lessonId: id,
  sectionId: `section-${id}`,
  moduleId,
  title: id,
  caption: '',
  videoUrl: `https://cdn.example/${id}.m3u8`,
  availableQualities: ['auto'] as VideoQuality[],
  isPreview: false,
  isLocked: false,
  isCompleted,
  reelNumber: Number(id.slice(-1)),
});
