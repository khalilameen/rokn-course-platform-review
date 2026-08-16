'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {
  stagingApiBase,
  verifyStagingApiContract,
} = require('../verify-staging-api-contract');

const jsonResponse = (status, body) => ({
  status,
  headers: new Headers({'content-type': 'application/json'}),
  json: async () => body,
});

const validPlans = [
  {code: 'basic', price_coins: 100, chat_enabled: false},
  {
    code: 'guided',
    price_coins: 200,
    chat_enabled: true,
    project_report_enabled: true,
  },
  {
    code: 'mentor',
    price_coins: 300,
    chat_enabled: true,
    project_output_enabled: true,
  },
];

const responseFor = (pathname, method = 'GET') => {
  if (pathname === '/api/health/launch-ready') {
    return jsonResponse(200, {success: true, status: 'launch_ready'});
  }
  if (pathname === '/api/product-features') {
    return jsonResponse(200, {
      data: {
        flags: {checkout: true, playback: true, project_uploads: true},
      },
    });
  }
  if (pathname === '/api/courses/64/details') {
    return jsonResponse(200, {data: {access_plans: validPlans}});
  }
  if (
    [
      '/api/wallet',
      '/api/learning/courses',
      '/api/user/watch-history',
      '/api/courses/64/full-track-upgrade',
      '/api/rewards/daily',
      '/api/lessons/641/playback-manifest',
      '/api/projects/684/submissions',
    ].includes(pathname)
  ) {
    return jsonResponse(401, {success: false, message: 'Unauthenticated'});
  }
  return jsonResponse(404, {message: `${method} route missing`});
};

const fakeFetch = async (url, options) =>
  responseFor(new URL(url).pathname, options?.method);

test('accepts a launch-ready backend with product, plan and route parity', async () => {
  const result = await verifyStagingApiContract({
    apiBase: 'https://staging.rokn.app/api/',
    courseId: 64,
    lessonId: 641,
    projectId: 684,
    fetchImpl: fakeFetch,
  });
  assert.deepEqual(result.planCodes, ['basic', 'guided', 'mentor']);
  assert.equal(result.checkedProtectedRoutes, 7);
});

test('rejects a deployed backend that is missing a protected route', async () => {
  await assert.rejects(
    verifyStagingApiContract({
      apiBase: 'https://staging.rokn.app/api/',
      courseId: 64,
      lessonId: 641,
      projectId: 684,
      fetchImpl: async (url, options) => {
        if (new URL(url).pathname === '/api/wallet') {
          return jsonResponse(404, {message: 'Not found'});
        }
        return fakeFetch(url, options);
      },
    }),
    /wallet returned HTTP 404/,
  );
});

test('rejects a course that does not expose all three plans', async () => {
  await assert.rejects(
    verifyStagingApiContract({
      apiBase: 'https://staging.rokn.app/api/',
      courseId: 64,
      lessonId: 641,
      projectId: 684,
      fetchImpl: async (url, options) => {
        if (new URL(url).pathname === '/api/courses/64/details') {
          return jsonResponse(200, {
            data: {access_plans: validPlans.slice(0, 2)},
          });
        }
        return fakeFetch(url, options);
      },
    }),
    /exactly basic, guided and mentor/,
  );
});

test('rejects an unavailable launch gate', async () => {
  await assert.rejects(
    verifyStagingApiContract({
      apiBase: 'https://staging.rokn.app/api/',
      courseId: 64,
      lessonId: 641,
      projectId: 684,
      fetchImpl: async (url, options) => {
        if (new URL(url).pathname === '/api/health/launch-ready') {
          return jsonResponse(503, {success: false, status: 'launch_blocked'});
        }
        return fakeFetch(url, options);
      },
    }),
    /launch-ready returned HTTP 503/,
  );
});

test('requires a credential-free HTTPS api base ending in api', () => {
  assert.throws(() => stagingApiBase('http://staging.rokn.app/api/'), /HTTPS/);
  assert.throws(
    () => stagingApiBase('https://user:pass@staging.rokn.app/api/'),
    /credential-free/,
  );
  assert.throws(
    () => stagingApiBase('https://staging.rokn.app/v1/'),
    /\/api\//,
  );
});
