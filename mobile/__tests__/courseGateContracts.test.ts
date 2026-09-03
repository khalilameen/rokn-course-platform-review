import fs from 'fs';
import path from 'path';

const source = (relativePath: string) =>
  fs.readFileSync(path.resolve(__dirname, '..', relativePath), 'utf8');

describe('course gate contracts', () => {
  it('keeps purchase and progression lock reasons in the API contract', () => {
    const baseResource = source('../backend/app/Http/Resources/BaseCourseResource.php');
    const learningResource = source('../backend/app/Http/Resources/CourseResource.php');
    const mapping = source('src/components/VideoPlayer/courseLearning/mapping.ts');

    expect(baseResource).toContain("'lock_reason' => $isPreview ? null : 'course_purchase_required'");
    expect(learningResource).toContain("['lock_reason'] ?? null");
    expect(mapping).toContain('valueAsString(section?.lock_reason)');
  });

  it('does not render a public payload as an enrolled project-gated course', () => {
    const loader = source('src/screens/reels/useReelsCourseLoader.ts');
    expect(loader).toContain("accessType === 'none'");
    expect(loader).toContain("navigation.replace('CourseDetails'");
  });

  it('renders one entitlement-derived course CTA instead of a duplicate sticky action', () => {
    const screen = source('src/screens/CourseDetails/index.tsx');
    expect(screen).toContain('primaryActionLabel={primaryActionLabel}');
    expect(screen).not.toContain('<StickyCourseAction');
    expect(screen).not.toContain('useStickyCourseAction');
  });
});
