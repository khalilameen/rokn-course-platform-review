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
      parseRoknDestination('https://rokn.app/course/42/watch/7'),
    ).toEqual({
      name: 'Reels',
      params: {courseId: '42', reelId: '7'},
    });
    expect(
      parseRoknDestination('rokn://course/42/watch?lesson_id=9'),
    ).toEqual({
      name: 'Reels',
      params: {courseId: '42', lessonId: '9'},
    });
    expect(
      parseRoknDestination(
        'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/course/42',
      ),
    ).toEqual({name: 'CourseDetails', params: {courseId: '42'}});
  });

  it('keeps detail links emitted by adjacent releases readable', () => {
    expect(parseRoknDestination('rokn://course-details/42')).toEqual({
      name: 'CourseDetails',
      params: {courseId: '42'},
    });
    expect(
      parseRoknDestination('https://rokn.app/api/courses/42/details'),
    ).toEqual({name: 'CourseDetails', params: {courseId: '42'}});
  });

  it('opens a non-enumerable support case without putting its access token in the link', () => {
    expect(
      parseRoknDestination('rokn://support/01JY7M7QW9WQQRF4S9V4Z0X7GA'),
    ).toEqual({
      name: 'Feedback',
      params: {caseId: '01JY7M7QW9WQQRF4S9V4Z0X7GA'},
    });
    expect(parseRoknDestination('rokn://support/42')).toBeNull();
    expect(
      parseRoknDestination(
        'rokn://support/01JY7M7QW9WQQRF4S9V4Z0X7GA/anything',
      ),
    ).toBeNull();
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
    expect(isExternalWebLink('https://rokn.com/course/42')).toBe(true);
    expect(
      isExternalWebLink(
        'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/course/42',
      ),
    ).toBe(false);
    expect(isExternalWebLink('http://support.example.org/help')).toBe(false);
    expect(isExternalWebLink('tel:+201000000000')).toBe(false);
  });
});
