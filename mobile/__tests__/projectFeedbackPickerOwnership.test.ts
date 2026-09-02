import fs from 'fs';
import path from 'path';

const source = fs.readFileSync(
  path.resolve(
    __dirname,
    '../src/components/VideoPlayer/ProjectTransition.tsx',
  ),
  'utf8',
);

describe('project feedback attachment ownership', () => {
  it('binds one picker flight to its project, thread and generation', () => {
    expect(source).toContain('feedbackPickerFlightRef.current');
    expect(source).toContain('activeProjectIdRef.current === projectId');
    expect(source).toContain('activeFeedbackThreadIdRef.current === threadId');
    expect(source).toContain('feedbackGenerationRef.current === generation');
    expect(source).toContain('[feedbackThread?.id, project.id]');
    expect(source).toContain(
      'const pickerBoundary = await captureAccountSessionBoundary()',
    );
    expect(source).toMatch(
      /cacheProjectFeedbackFile\([\s\S]*?pickerBoundary[\s\S]*?assertAccountSessionBoundary\(pickerBoundary\)/,
    );
  });

  it('removes copied files when the picker loses ownership', () => {
    expect(source).toMatch(
      /if \(!ownsPicker\(\)\) \{\s*await Promise\.all\(additions\.map\(removeLearnerDraftFile\)\)/,
    );
    expect(source).toMatch(
      /if \(!ownsPickerContext\(\)\) \{\s*void Promise\.all\(additions\.map\(removeLearnerDraftFile\)\)/,
    );
  });
});
