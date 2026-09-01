import fs from 'node:fs';
import path from 'node:path';

const source = (relativePath: string) =>
  fs.readFileSync(path.resolve(__dirname, '..', relativePath), 'utf8');

describe('learning async ownership contracts', () => {
  it('does not let a previous project operation update the active project', () => {
    const project = source(
      'src/components/VideoPlayer/ProjectTransition.tsx',
    );
    expect(project).toContain('projectGenerationRef.current += 1');
    expect(project).toContain(
      'if (!ownsProject(projectId, projectGeneration)) return;',
    );
    expect(project).toContain('setSubmissionSending(false)');
  });

  it('does not restart a new reel when an old source refresh settles', () => {
    const player = source('src/components/VideoPlayer/VideoComponent.tsx');
    expect(player).toContain('playbackLifecycleGenerationRef.current += 1');
    expect(player).toContain('reelIdentityRef.current !== reelId');
    expect(player).toContain('clearTimeout(recoveryTimerRef.current)');
  });

  it('isolates upgrade and notification flights across accounts', () => {
    const chat = source(
      'src/components/VideoPlayer/courseChat/useCourseChat.ts',
    );
    const notifications = source('src/screens/Notifications.tsx');
    expect(chat).toContain('upgradeGenerationRef.current !== upgradeGeneration');
    expect(chat).toContain(
      '[accountEpoch, course.accessType, course.chatAvailable, courseId]',
    );
    expect(notifications).toContain('new Map<string, symbol>()');
    expect(notifications).toContain(
      'readFlightsRef.current.get(item.id) === flight',
    );
  });
});
