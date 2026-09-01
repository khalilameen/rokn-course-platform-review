import {normalizeText} from '../../utils/searchText';
import type {DemoCourse} from '../../data/demoContent';
import {recommendCourses} from '../../services/courseRecommendations';

export type HomeCourseSection = {
  id: string;
  title: string;
  data: DemoCourse[];
};

const byHomeOrder = (first: DemoCourse, second: DemoCourse) =>
  (first.homeSortOrder ?? 100) - (second.homeSortOrder ?? 100) ||
  first.title.localeCompare(second.title, 'ar') ||
  String(first.id).localeCompare(String(second.id), 'en', {numeric: true});

export const buildHomeSections = ({
  catalogue,
  demoCatalogue,
  demoSections,
  usingLocalDemo,
}: {
  catalogue: DemoCourse[];
  demoCatalogue: DemoCourse[];
  demoSections: HomeCourseSection[];
  usingLocalDemo: boolean;
}): HomeCourseSection[] => {
  if (usingLocalDemo) {
    const currentCourseById = new Map(
      demoCatalogue.map(course => [course.id, course]),
    );
    return demoSections.map(section => ({
      ...section,
      data: section.data.map(
        course => currentCourseById.get(course.id) ?? course,
      ),
    }));
  }

  const ownedCourses = catalogue
    .filter(course => course.owned && course.published !== false)
    .sort((first, second) => {
      const progressOrder =
        Number(second.progress || 0) - Number(first.progress || 0);
      return progressOrder || byHomeOrder(first, second);
    });
  const continueCourses = ownedCourses.filter(
    course => Number(course.progress || 0) > 0 && Number(course.progress) < 100,
  );
  const rowMap = new Map<
    string,
    {id: string; title: string; order: number; data: DemoCourse[]}
  >();

  catalogue.forEach(course => {
    if (course.published === false) return;
    course.homeRows?.forEach(row => {
      const current = rowMap.get(row.id) ?? {
        id: `classification-${row.id}`,
        title: row.title,
        order: row.order,
        data: [],
      };
      if (!current.data.some(item => item.id === course.id)) {
        current.data.push(course);
      }
      current.order = Math.min(current.order, row.order);
      rowMap.set(row.id, current);
    });
  });

  const configuredRows = [...rowMap.values()]
    .map(row => ({...row, data: row.data.sort(byHomeOrder)}))
    .sort(
      (first, second) =>
        first.order - second.order ||
        first.title.localeCompare(second.title, 'ar'),
    );
  const unassigned = catalogue.filter(course => !course.homeRows?.length);
  const fallbackPublished = unassigned
    .filter(course => course.published !== false)
    .sort(byHomeOrder);
  const fallbackUpcoming = catalogue
    .filter(course => course.published === false)
    .sort(byHomeOrder);

  return [
    continueCourses.length
      ? {
          id: 'continue-learning',
          title: 'أكمل من مكانك',
          data: continueCourses,
        }
      : null,
    ...configuredRows,
    fallbackPublished.length
      ? {id: 'published', title: 'كورسات مختارة لك', data: fallbackPublished}
      : null,
    fallbackUpcoming.length
      ? {id: 'upcoming', title: 'قريبًا في ركن', data: fallbackUpcoming}
      : null,
  ].filter((section): section is HomeCourseSection => Boolean(section));
};

export const selectHeroCourses = ({
  catalogue,
  demoCourse,
  usingLocalDemo,
}: {
  catalogue: DemoCourse[];
  demoCourse: DemoCourse;
  usingLocalDemo: boolean;
}): DemoCourse[] =>
  usingLocalDemo
    ? [demoCourse]
    : [
        catalogue.find(
          course => course.published !== false && course.isMainCourse === true,
        ) ?? catalogue.find(course => course.published !== false),
      ].filter((course): course is DemoCourse => Boolean(course));

export const selectHomeRecommendations = (
  catalogue: DemoCourse[],
  heroCourses: DemoCourse[],
): DemoCourse[] => {
  const preferredCategories = catalogue
    .filter(course => course.owned === true || Number(course.progress || 0) > 0)
    .map(course => course.category);

  return recommendCourses(catalogue, {
    excludedCourseIds: heroCourses.map(course => course.id),
    preferredCategories,
  });
};

export const buildQuickSearches = (
  catalogue: DemoCourse[],
  defaults: string[],
): string[] =>
  Array.from(
    new Set([
      ...catalogue.flatMap(course =>
        (course.homeRows || []).map(row => row.title),
      ),
      ...defaults,
    ]),
  )
    .filter(Boolean)
    .slice(0, 6);

export const searchHomeCatalogue = ({
  browseCatalogue,
  catalogue,
  demoCatalogue,
  remoteCourses,
  searchQuery,
  loadedSearchQuery,
  usingLocalDemo,
}: {
  browseCatalogue: DemoCourse[];
  catalogue: DemoCourse[];
  demoCatalogue: DemoCourse[];
  remoteCourses: DemoCourse[] | null;
  searchQuery: string;
  loadedSearchQuery: string;
  usingLocalDemo: boolean;
}): DemoCourse[] => {
  const query = normalizeText(searchQuery);
  if (!query) return [];
  const resultQuery = normalizeText(loadedSearchQuery);
  const remoteBelongsToCurrentQuery =
    remoteCourses !== null && resultQuery === query;

  // A server search may match keywords and descriptions that are deliberately
  // absent from the compact card. Filtering that result again on the phone
  // creates false empty states. Once the response arrives, it is authoritative.
  if (
    !usingLocalDemo &&
    remoteBelongsToCurrentQuery
  ) {
    return Array.from(
      new Map(catalogue.map(course => [course.id, course])).values(),
    );
  }

  const source = usingLocalDemo
    ? demoCatalogue
    : remoteBelongsToCurrentQuery
    ? catalogue
    : browseCatalogue;

  const seen = new Set<string>();
  const queryTokens = query.split(' ').filter(Boolean);
  return source.filter(course => {
    if (seen.has(course.id)) return false;
    const searchable = normalizeText([
      course.title,
      course.description,
      course.instructor,
      course.label,
      ...(course.homeRows?.map(row => row.title) || []),
    ]
      .filter((value): value is string => Boolean(value))
      .join(' '));
    const matches =
      searchable.includes(query) ||
      queryTokens.every(token => searchable.includes(token));
    if (matches) seen.add(course.id);
    return matches;
  });
};
