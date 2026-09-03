'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const {isCourseDuration} = require('../production-entry-contract');

test('accepts every Arabic course-duration shape rendered by the app', () => {
  for (const value of ['دقيقة', 'دقيقتان', '٨ دقائق', '٦٢ دقيقة']) {
    assert.equal(isCourseDuration(value), true, value);
  }
  for (const value of ['', 'دقائق', 'لا توجد مدة', '٦٢ طالبًا']) {
    assert.equal(isCourseDuration(value), false, value);
  }
});
