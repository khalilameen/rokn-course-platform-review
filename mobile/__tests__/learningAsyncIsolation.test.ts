import fs from 'node:fs';
import path from 'node:path';

const source = (relativePath: string) =>
  fs.readFileSync(path.resolve(__dirname, '..', relativePath), 'utf8');

describe('learning async ownership contracts', () => {
  it('does not let a previous project operation update the active project', () => {
    const project = source('src/components/VideoPlayer/ProjectTransition.tsx');
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
    expect(player).toContain('clearTimeout(longBufferTimerRef.current)');
    expect(player).toContain('clearTimeout(recoveryTimerRef.current)');
  });

  it('isolates upgrade and notification flights across accounts', () => {
    const chat = source(
      'src/components/VideoPlayer/courseChat/useCourseChat.ts',
    );
    const notifications = source('src/screens/Notifications.tsx');
    expect(chat).toContain(
      'upgradeGenerationRef.current !== upgradeGeneration',
    );
    expect(chat).toContain(
      '[accountEpoch, course.accessType, course.chatAvailable, courseId]',
    );
    expect(chat).toContain(
      'stopConversationGeneration !== conversationGenerationRef.current',
    );
    expect(chat).toMatch(
      /sendGenerationRef\.current \+= 1;[\s\S]*setSending\(false\);[\s\S]*await cancelCourseAssistantTurn/,
    );
    expect(chat).toMatch(
      /await pollCourseAssistantTurn\(clientRequestId\);[\s\S]*sendGeneration !== sendGenerationRef\.current/,
    );
    const chatOverlay = source(
      'src/components/VideoPlayer/CourseChatOverlay.tsx',
    );
    expect(chatOverlay).toContain('attachmentPickerGenerationRef.current += 1');
    expect(chatOverlay).toContain('if (!ownsPicker())');
    expect(notifications).toContain('new Map<string, symbol>()');
    expect(notifications).toContain(
      'readFlightsRef.current.get(item.id) === flight',
    );
  });

  it('does not let a completed course transaction mutate the next course route', () => {
    const details = source('src/screens/CourseDetails/index.tsx');

    expect(details).toContain('courseOperationGenerationRef.current += 1;');
    expect(details).toContain('activeCourseIdRef.current === expectedCourseId');
    expect(details).toMatch(
      /const result = await purchaseCourse\([\s\S]*if \(!ownsCourseOperation\(operationCourseId, operationGeneration\)\)/,
    );
    expect(details).toMatch(
      /const result = await openCoinCheckout\([\s\S]*if \(!ownsCourseOperation\(operationCourseId, operationGeneration\)\)/,
    );
    expect(details).toMatch(
      /finally \{\s*if \(ownsCourseOperation\(operationCourseId, operationGeneration\)\)/,
    );
  });

  it('keeps project and completion writes owned by their starting account', () => {
    const projects = source(
      'src/components/VideoPlayer/courseLearning/projects.ts',
    );
    const playback = source(
      'src/components/VideoPlayer/courseLearning/playback.ts',
    );

    expect(projects).toContain(
      'const boundary = await captureAccountSessionBoundary();',
    );
    expect(projects).toContain('assertProjectOwner(generation, boundary);');
    expect(projects).toContain(
      'const storageKey = await projectSubmissionKey(projectId, accountScope);',
    );
    expect(projects).toContain(
      'await markProjectProvisional(projectId, accountScope, boundary);',
    );
    expect(projects).toContain(
      'projectSubmissionFlights.get(flightKey) === flight',
    );

    expect(playback).toContain('updatePlayerStateForScope(');
    expect(playback).toContain('assertCompletionOwner(generation, boundary);');
    expect(playback).toContain(
      'const flightKey = `${accountScope}:${boundary.epoch}:${courseId}:${sectionId}`;',
    );
    expect(playback).toContain('assertPlaybackRuntime(generation);');
    expect(playback).toContain(
      'sectionCompletionFlights.get(flightKey) === flight',
    );
  });
});
