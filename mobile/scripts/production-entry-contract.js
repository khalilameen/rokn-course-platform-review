'use strict';

// Arabic singular and dual forms carry the count in the noun itself. Treat
// them as real duration labels instead of forcing an unnatural visible digit.
const isCourseDuration = value =>
  /^(?:دقيقة|دقيقتان|[٠-٩0-9]+\s+(?:دقائق|دقيقة))$/.test(
    String(value || '').trim(),
  );

module.exports = {isCourseDuration};
