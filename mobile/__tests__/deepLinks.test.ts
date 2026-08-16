import {
  isExternalWebLink,
  parseRoknDestination,
} from '../src/navigation/deepLinks';

describe('Rokn deep links', () => {
  it('opens singular and legacy plural course links consistently', () => {
    expect(parseRoknDestination('rokn://course/42')).toEqual({
      name: 'CourseDetails',
      params: {courseId: '42'},
    });
    expect(parseRoknDestination('/courses/42')).toEqual({
      name: 'CourseDetails',
      params: {courseId: '42'},
    });
  });

  it('restores an exact learning step from a notification', () => {
    expect(
      parseRoknDestination('https://rokn.app/course/design/watch/lesson-7'),
    ).toEqual({
      name: 'Reels',
      params: {courseId: 'design', reelId: 'lesson-7'},
    });
  });

  it('rejects incomplete internal links instead of navigating silently', () => {
    expect(parseRoknDestination('/course')).toBeNull();
    expect(parseRoknDestination('rokn://unknown')).toBeNull();
    expect(parseRoknDestination('rokn://course/%2e%2e%2fwallet')).toBeNull();
    expect(
      parseRoknDestination('rokn://course/42/watch/%2fadmin'),
    ).toBeNull();
    expect(
      parseRoknDestination(`rokn://course/${'a'.repeat(129)}`),
    ).toBeNull();
  });

  it('distinguishes external web destinations from Rokn routes', () => {
    expect(isExternalWebLink('https://support.example.org/help')).toBe(true);
    expect(isExternalWebLink('https://rokn.app/course/42')).toBe(false);
    expect(isExternalWebLink('https://rokn.com/course/42')).toBe(false);
    expect(isExternalWebLink('http://support.example.org/help')).toBe(false);
    expect(isExternalWebLink('tel:+201000000000')).toBe(false);
  });
});
