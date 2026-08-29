'use strict';

const requiredFlags = ['checkout', 'playback', 'project_uploads'];
const requiredPlanCodes = ['basic', 'guided', 'mentor'];

const publicRoutes = [
  ['GET', 'auth-methods'],
  ['GET', 'packages'],
  ['GET', 'paths'],
  ['GET', 'settings'],
  ['GET', 'content/pages/about'],
  ['GET', 'content/pages/privacy'],
  ['GET', 'content/pages/contact'],
];

const requiredValue = (value, name) => {
  const normalized = String(value || '').trim();
  if (!normalized) throw new Error(`${name} is required.`);
  return normalized;
};

const positiveInteger = (value, name) => {
  const number = Number(requiredValue(value, name));
  if (!Number.isSafeInteger(number) || number < 1) {
    throw new Error(`${name} must be a positive integer.`);
  }
  return number;
};

const stagingApiBase = value => {
  const url = new URL(requiredValue(value, 'ROKN_SMOKE_API_BASE'));
  if (
    url.protocol !== 'https:' ||
    url.username ||
    url.password ||
    url.search ||
    url.hash ||
    !url.pathname.endsWith('/api/')
  ) {
    throw new Error(
      'ROKN_SMOKE_API_BASE must be a credential-free HTTPS URL ending in /api/.',
    );
  }
  return url;
};

const responseJson = async (fetchImpl, base, path, expectedStatus, method) => {
  const response = await fetchImpl(new URL(path, base), {
    method,
    redirect: 'error',
    headers: {Accept: 'application/json'},
    signal: AbortSignal.timeout(15_000),
  });
  if (response.status !== expectedStatus) {
    throw new Error(
      `${method} ${path} returned HTTP ${response.status}; expected ${expectedStatus}.`,
    );
  }
  const contentType = response.headers.get('content-type') || '';
  if (!contentType.toLowerCase().includes('application/json')) {
    throw new Error(`${method} ${path} did not return JSON.`);
  }
  return response.json();
};

const verifyPlans = payload => {
  const plans = payload?.data?.access_plans;
  if (!Array.isArray(plans)) {
    throw new Error(
      'The staging course details response has no access_plans array.',
    );
  }
  const byCode = new Map(plans.map(plan => [plan?.code, plan]));
  if (
    plans.length !== requiredPlanCodes.length ||
    requiredPlanCodes.some(code => !byCode.has(code))
  ) {
    throw new Error(
      'The staging course does not expose exactly basic, guided and mentor plans.',
    );
  }
  const prices = requiredPlanCodes.map(code =>
    Number(byCode.get(code).price_coins),
  );
  if (
    prices.some(price => !Number.isSafeInteger(price) || price < 0) ||
    prices[1] < prices[0] ||
    prices[2] < prices[1]
  ) {
    throw new Error('The staging plan prices are invalid or out of order.');
  }
  if (
    byCode.get('basic').chat_enabled !== false ||
    byCode.get('guided').chat_enabled !== true ||
    byCode.get('guided').project_report_enabled !== true ||
    byCode.get('mentor').chat_enabled !== true ||
    byCode.get('mentor').project_output_enabled !== true
  ) {
    throw new Error(
      'The staging plan benefits do not match the Rokn product contract.',
    );
  }
};

const verifyStagingApiContract = async ({
  apiBase,
  courseId,
  lessonId,
  projectId,
  fetchImpl = fetch,
}) => {
  const base = stagingApiBase(apiBase);
  const fixtures = {
    courseId: positiveInteger(courseId, 'ROKN_SMOKE_COURSE_ID'),
    lessonId: positiveInteger(lessonId, 'ROKN_SMOKE_LESSON_ID'),
    projectId: positiveInteger(projectId, 'ROKN_SMOKE_PROJECT_ID'),
  };

  const launch = await responseJson(
    fetchImpl,
    base,
    'health/launch-ready',
    200,
    'GET',
  );
  if (launch?.success !== true || launch?.status !== 'launch_ready') {
    throw new Error('The staging backend did not confirm launch readiness.');
  }

  const features = await responseJson(
    fetchImpl,
    base,
    'product-features?bucket=0',
    200,
    'GET',
  );
  for (const flag of requiredFlags) {
    if (features?.data?.flags?.[flag] !== true) {
      throw new Error(`The staging feature flag '${flag}' is not enabled.`);
    }
  }

  const details = await responseJson(
    fetchImpl,
    base,
    `courses/${fixtures.courseId}/details`,
    200,
    'GET',
  );
  if (!String(details?.data?.title || '').trim()) {
    throw new Error('The staging course details response has an empty title.');
  }
  verifyPlans(details);

  for (const [method, path] of publicRoutes) {
    const response = await responseJson(fetchImpl, base, path, 200, method);
    if (response?.success !== true) {
      throw new Error(`${method} ${path} did not return a successful API envelope.`);
    }
  }

  const protectedRoutes = [
    ['GET', 'wallet'],
    ['GET', 'learning/courses'],
    ['GET', 'user/paths'],
    ['GET', 'user/profile'],
    ['GET', 'user/watch-history'],
    ['GET', 'certificates'],
    ['GET', 'notifications'],
    ['GET', 'saved-folders'],
    ['GET', 'saved-lessons'],
    ['GET', 'portfolio'],
    ['GET', 'portfolio-profile'],
    ['GET', `courses/${fixtures.courseId}/full-track-upgrade`],
    ['POST', 'rewards/daily'],
    ['POST', 'payment/initiate'],
    ['POST', `certificates/${fixtures.courseId}/issue`],
    ['POST', `lessons/${fixtures.lessonId}/playback-manifest`],
    ['POST', `projects/${fixtures.projectId}/submissions`],
  ];
  for (const [method, path] of protectedRoutes) {
    await responseJson(fetchImpl, base, path, 401, method);
  }

  return {
    apiBase: base.href,
    checkedPublicRoutes: publicRoutes.length,
    checkedProtectedRoutes: protectedRoutes.length,
    courseId: fixtures.courseId,
    planCodes: requiredPlanCodes,
  };
};

if (require.main === module) {
  verifyStagingApiContract({
    apiBase: process.env.ROKN_SMOKE_API_BASE,
    courseId: process.env.ROKN_SMOKE_COURSE_ID,
    lessonId: process.env.ROKN_SMOKE_LESSON_ID,
    projectId: process.env.ROKN_SMOKE_PROJECT_ID,
  })
    .then(result => {
      console.log(JSON.stringify(result, null, 2));
      console.log('Staging backend product contract passed.');
    })
    .catch(error => {
      console.error(error.message);
      console.error(
        'Do not run or approve the mobile staging smoke against this backend.',
      );
      process.exitCode = 2;
    });
}

module.exports = {
  stagingApiBase,
  verifyPlans,
  verifyStagingApiContract,
};
