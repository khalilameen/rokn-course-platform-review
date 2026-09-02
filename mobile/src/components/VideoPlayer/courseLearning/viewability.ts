import type {ViewToken} from 'react-native';

/**
 * Android can report the outgoing full-screen cell first during a vertical
 * swipe. Choose the cell with the greatest real viewport coverage; direction
 * resolves only an exact tie.
 */
export const selectPrimaryViewableItem = <T>(
  items: ViewToken<T>[],
  scrollOffset: number,
  viewportExtent: number,
  direction: number,
): ViewToken<T> | undefined => {
  const extent = Math.max(1, viewportExtent);
  const viewportStart = Math.max(0, scrollOffset);
  const viewportEnd = viewportStart + extent;
  return items
    .filter(item => item.isViewable && typeof item.index === 'number')
    .map(item => {
      const itemStart = Number(item.index) * extent;
      const overlap = Math.max(
        0,
        Math.min(viewportEnd, itemStart + extent) - Math.max(viewportStart, itemStart),
      );
      return {item, coverage: overlap / extent};
    })
    .sort((left, right) => {
      if (left.coverage !== right.coverage) return right.coverage - left.coverage;
      return direction > 0
        ? Number(right.item.index) - Number(left.item.index)
        : Number(left.item.index) - Number(right.item.index);
    })[0]?.item;
};
