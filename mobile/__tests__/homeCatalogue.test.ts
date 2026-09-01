import type {DemoCourse} from '../src/data/demoContent';
import {
  buildHomeSections,
  buildQuickSearches,
  searchHomeCatalogue,
  selectHeroCourses,
} from '../src/screens/home/homeCatalogue';

const course = (
  id: string,
  overrides: Partial<DemoCourse> = {},
): DemoCourse => ({
  id,
  title: id,
  description: `وصف ${id}`,
  instructor: 'مدرب',
  image: 1,
  category: 'skills',
  published: true,
  ...overrides,
});

describe('home catalogue presentation', () => {
  test('builds configured, continuation, and upcoming rows', () => {
    const learning = course('learning', {owned: true, progress: 35});
    const classified = course('classified', {
      homeRows: [{id: 'design', title: 'التصميم', order: 2}],
    });
    const upcoming = course('upcoming', {published: false});

    const rows = buildHomeSections({
      catalogue: [upcoming, classified, learning],
      demoCatalogue: [],
      demoSections: [],
      usingLocalDemo: false,
    });

    expect(rows.map(row => row.id)).toEqual([
      'continue-learning',
      'classification-design',
      'published',
      'upcoming',
    ]);
    expect(rows[0].data).toEqual([learning]);
  });

  test('keeps the configured demo rows and replaces their course state', () => {
    const stale = course('demo', {owned: false});
    const current = course('demo', {owned: true});

    const rows = buildHomeSections({
      catalogue: [],
      demoCatalogue: [current],
      demoSections: [{id: 'demo-row', title: 'ابدأ هنا', data: [stale]}],
      usingLocalDemo: true,
    });

    expect(rows[0].data).toEqual([current]);
  });

  test('selects a published hero and searches normalized Arabic text', () => {
    const hidden = course('hidden', {published: false, isMainCourse: true});
    const hero = course('hero', {
      title: 'صناعة المحتوى',
      isMainCourse: true,
    });

    expect(
      selectHeroCourses({
        catalogue: [hidden, hero],
        demoCourse: hidden,
        usingLocalDemo: false,
      }),
    ).toEqual([hero]);
    expect(
      searchHomeCatalogue({
        browseCatalogue: [],
        catalogue: [hero],
        demoCatalogue: [],
        remoteCourses: [hero],
        searchQuery: 'المحتوي',
        loadedSearchQuery: 'المحتوي',
        usingLocalDemo: false,
      }),
    ).toEqual([hero]);
  });

  test('deduplicates server row names before fallback searches', () => {
    const first = course('first', {
      homeRows: [{id: 'skills', title: 'المهارات', order: 1}],
    });
    const second = course('second', {
      homeRows: [{id: 'skills', title: 'المهارات', order: 1}],
    });

    expect(buildQuickSearches([first, second], ['العمل الحر'])).toEqual([
      'المهارات',
      'العمل الحر',
    ]);
  });

  test('does not present results from an older rapid search as authoritative', () => {
    const oldResult = course('old', {title: 'التسويق'});
    const currentLocalMatch = course('current', {
      title: 'التصميم',
      instructor: 'أحمد',
    });

    expect(
      searchHomeCatalogue({
        browseCatalogue: [oldResult, currentLocalMatch],
        catalogue: [oldResult],
        demoCatalogue: [],
        remoteCourses: [oldResult],
        searchQuery: 'احمد',
        loadedSearchQuery: 'التسويق',
        usingLocalDemo: false,
      }),
    ).toEqual([currentLocalMatch]);
  });
});
