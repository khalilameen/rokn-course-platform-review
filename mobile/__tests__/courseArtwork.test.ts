import {isSvgCourseArtwork} from '../src/components/ui/CourseArtwork';

describe('course artwork compatibility', () => {
  it('renders remote SVG course covers through the SVG renderer', () => {
    expect(
      isSvgCourseArtwork({
        uri: 'https://rokn.app/images/course-cover.svg?version=2',
      }),
    ).toBe(true);
    expect(
      isSvgCourseArtwork({uri: 'https://rokn.app/images/course-cover.webp'}),
    ).toBe(false);
  });
});
