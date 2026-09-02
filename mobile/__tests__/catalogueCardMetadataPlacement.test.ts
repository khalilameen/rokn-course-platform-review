import fs from 'fs';
import path from 'path';

const source = (relativePath: string) =>
  fs.readFileSync(path.resolve(__dirname, '..', relativePath), 'utf8');

describe('public course metadata placement', () => {
  it('does not render duration, students, or ratings on home catalogue cards', () => {
    const courseCard = source('src/components/view/CourseCard.tsx');
    const carouselCard = source('src/components/view/CarouselItem.tsx');

    expect(courseCard).not.toMatch(
      /item\.(durationMinutes|ratingAverage|ratingsCount|studentsCount)/,
    );
    expect(carouselCard).not.toMatch(
      /course\.(durationMinutes|ratingAverage|ratingsCount|studentsCount)/,
    );
  });

  it('keeps those decision metrics on the course details surface', () => {
    const courseDetails = source('src/screens/CourseDetails/index.tsx');

    expect(courseDetails).toContain('durationMinutes={durationMinutes}');
    expect(courseDetails).toContain('ratingAverage={ratingAverage}');
    expect(courseDetails).toContain('ratingsCount={ratingsCount}');
    expect(courseDetails).toContain('studentsCount={studentsCount}');
  });
});
