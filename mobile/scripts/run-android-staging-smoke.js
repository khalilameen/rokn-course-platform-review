'use strict';

/*
 * Runs the release smoke suite against a real staging Android device. Missing
 * credentials stop the run instead of producing incomplete evidence.
 */
const {existsSync} = require('fs');
const {spawnSync} = require('child_process');
const path = require('path');

const root = path.resolve(__dirname, '..');
const e2eRoot = path.join(root, 'e2e', 'maestro');
const maestroVersion = '1.39.0';
const coreSelectors = [
  'ROKN_SMOKE_EMAIL',
  'ROKN_SMOKE_PASSWORD',
  'ROKN_SMOKE_AUTH_PROVIDER_LABEL',
  'ROKN_SMOKE_OAUTH_EMAIL_FIELD',
  'ROKN_SMOKE_OAUTH_PASSWORD_FIELD',
  'ROKN_SMOKE_LEARN_CTA',
  'ROKN_SMOKE_COURSE_TITLE',
  'ROKN_SMOKE_PAID_COURSE_TITLE',
  'ROKN_SMOKE_CHECKOUT_CTA',
  'ROKN_SMOKE_CHECKOUT_READY_TEXT',
  'ROKN_SMOKE_OFFLINE_NOTICE_TEXT',
  'ROKN_SMOKE_PROJECT_SUBMIT_CTA',
  'ROKN_SMOKE_QUEUED_PROJECT_TEXT',
  'ROKN_SMOKE_PROFILE_TAB',
  'ROKN_SMOKE_EDIT_ACCOUNT_CTA',
  'ROKN_SMOKE_DELETE_ACCOUNT_CTA',
  'ROKN_SMOKE_DELETE_ACCOUNT_TEXT',
];
const forcedUpdateSelectors = ['ROKN_SMOKE_FORCED_UPDATE_TEXT'];
const fixtureConfiguration = [
  'ROKN_SMOKE_FORCED_UPDATE_FIXTURE_URL',
  'ROKN_SMOKE_FIXTURE_TOKEN',
  'ROKN_SMOKE_APK_VERSION_CODE',
  'ROKN_SMOKE_RUN_ID',
];
const fixtureTtlSeconds = 900;

const failForMissing = (names, label) => {
  const missing = names.filter(name => !String(process.env[name] || '').trim());
  if (!missing.length) return;
  const error = new Error(
    `${label} was NOT run. Missing required protected configuration:\n${missing
      .map(name => `- ${name}`)
      .join('\n')}`,
  );
  error.code = 2;
  throw error;
};

const commandExists = command => {
  const resolver = process.platform === 'win32' ? 'where' : 'which';
  return spawnSync(resolver, [command], {stdio: 'ignore'}).status === 0;
};

const requireTooling = () => {
  if (!commandExists('maestro')) {
    const error = new Error(
      'Maestro CLI is required. Install the pinned version described in e2e/maestro/README.md.',
    );
    error.code = 2;
    throw error;
  }
  const installedMaestro = spawnSync('maestro', ['--version'], {
    encoding: 'utf8',
  });
  const installedVersion = `${installedMaestro.stdout || ''}${
    installedMaestro.stderr || ''
  }`.trim();
  if (
    installedMaestro.status !== 0 ||
    !new RegExp(`(^|\\s)${maestroVersion.replaceAll('.', '\\.')}($|\\s)`).test(
      installedVersion,
    )
  ) {
    const error = new Error(
      `Maestro ${maestroVersion} is required; found ${
        installedVersion || 'an unreadable version'
      }.`,
    );
    error.code = 2;
    throw error;
  }
  if (!commandExists('adb')) {
    const error = new Error('Android platform-tools (adb) are required.');
    error.code = 2;
    throw error;
  }
};

const serial = process.env.ANDROID_SERIAL
  ? ['-s', process.env.ANDROID_SERIAL]
  : [];
const packageName = process.env.ROKN_SMOKE_APP_ID || 'com.rokn';

const adb = args => {
  const result = spawnSync('adb', args, {encoding: 'utf8'});
  if (result.status !== 0) {
    const error = new Error(
      result.stderr || result.stdout || 'adb command failed.',
    );
    error.code = result.status || 1;
    throw error;
  }
  return result.stdout || '';
};

const maestroEnvironment = names =>
  names.flatMap(name => ['-e', `${name}=${process.env[name]}`]);

const run = (flow, selectors) => {
  const file = path.join(e2eRoot, flow);
  const result = spawnSync(
    'maestro',
    [...maestroEnvironment(selectors), 'test', file],
    {
      cwd: root,
      stdio: 'inherit',
    },
  );
  if (result.status !== 0) {
    const error = new Error(`Maestro flow failed: ${flow}`);
    error.code = result.status || 1;
    throw error;
  }
};

const setNetwork = enabled => {
  // This uses a test emulator/device only. The outer finally restores network
  // even if a Maestro assertion or forced-update fixture request fails.
  adb([...serial, 'shell', 'svc', 'wifi', enabled ? 'enable' : 'disable']);
  adb([...serial, 'shell', 'svc', 'data', enabled ? 'enable' : 'disable']);
};

const fixtureUrl = () => {
  const url = new URL(process.env.ROKN_SMOKE_FORCED_UPDATE_FIXTURE_URL);
  if (url.protocol !== 'https:' || url.username || url.password || url.hash) {
    const error = new Error(
      'ROKN_SMOKE_FORCED_UPDATE_FIXTURE_URL must be a credential-free HTTPS URL without a fragment.',
    );
    error.code = 2;
    throw error;
  }
  return url;
};

const fixtureRequest = async payload => {
  const response = await fetch(fixtureUrl(), {
    method: 'POST',
    redirect: 'error',
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${process.env.ROKN_SMOKE_FIXTURE_TOKEN}`,
      'Content-Type': 'application/json',
      'Idempotency-Key': `${process.env.ROKN_SMOKE_RUN_ID}:${payload.action}`,
    },
    body: JSON.stringify(payload),
    signal: AbortSignal.timeout(15_000),
  });
  if (!response.ok) {
    throw new Error(
      `Forced-update fixture ${payload.action} failed with HTTP ${response.status}.`,
    );
  }
  const contentType = response.headers.get('content-type') || '';
  if (!contentType.toLowerCase().includes('application/json')) {
    throw new Error(
      `Forced-update fixture ${payload.action} did not return JSON.`,
    );
  }
  return response.json();
};

const activateForcedUpdate = async () => {
  const versionCode = Number(process.env.ROKN_SMOKE_APK_VERSION_CODE);
  if (!Number.isSafeInteger(versionCode) || versionCode < 1) {
    const error = new Error(
      'ROKN_SMOKE_APK_VERSION_CODE must be a positive integer.',
    );
    error.code = 2;
    throw error;
  }
  const runId = process.env.ROKN_SMOKE_RUN_ID;
  const response = await fixtureRequest({
    action: 'activate',
    applicationId: packageName,
    platform: 'android',
    runId,
    ttlSeconds: fixtureTtlSeconds,
    versionCode,
  });
  const expiresAt = Date.parse(response.expiresAt);
  const now = Date.now();
  if (
    response.active !== true ||
    !/^[A-Za-z0-9._:-]{8,200}$/.test(String(response.leaseId || '')) ||
    response.applicationId !== packageName ||
    Number(response.versionCode) !== versionCode ||
    response.runId !== runId ||
    !Number.isFinite(expiresAt) ||
    expiresAt <= now + 30_000 ||
    expiresAt > now + (fixtureTtlSeconds + 30) * 1_000
  ) {
    throw new Error(
      'Forced-update fixture activation response was not a bounded lease for this APK/run.',
    );
  }
  return {
    leaseId: response.leaseId,
    runId,
    versionCode,
  };
};

const deactivateForcedUpdate = async lease => {
  const response = await fixtureRequest({
    action: 'deactivate',
    applicationId: packageName,
    leaseId: lease.leaseId,
    platform: 'android',
    runId: lease.runId,
    versionCode: lease.versionCode,
  });
  if (response.released !== true || response.leaseId !== lease.leaseId) {
    throw new Error(
      'Forced-update fixture teardown did not confirm release of this run lease.',
    );
  }
};

const runForcedUpdateFlow = async () => {
  // Core and destructive-account flows have already completed at this point.
  // The policy exists only for flow 06 and is bounded by a server-side TTL.
  failForMissing(
    [...forcedUpdateSelectors, ...fixtureConfiguration],
    'Forced-update smoke',
  );
  const lease = await activateForcedUpdate();
  let flowError;
  let cleanupError;
  try {
    run('06-forced-update.yaml', forcedUpdateSelectors);
  } catch (error) {
    flowError = error;
  } finally {
    try {
      await deactivateForcedUpdate(lease);
    } catch (error) {
      cleanupError = error;
    }
  }
  if (flowError && cleanupError) {
    throw new AggregateError(
      [flowError, cleanupError],
      'Forced-update flow and mandatory fixture teardown both failed.',
    );
  }
  if (cleanupError) throw cleanupError;
  if (flowError) throw flowError;
};

const main = async () => {
  failForMissing(coreSelectors, 'Core staging smoke');
  requireTooling();

  const apk = process.env.ROKN_SMOKE_APK;
  if (apk) {
    if (!existsSync(apk)) {
      const error = new Error(`ROKN_SMOKE_APK does not exist: ${apk}`);
      error.code = 2;
      throw error;
    }
    adb([...serial, 'install', '-r', apk]);
  }
  const packagePath = adb([...serial, 'shell', 'pm', 'path', packageName]);
  if (!packagePath.includes('package:')) {
    const error = new Error(
      `The staging artifact is not installed (${packageName}). Set ROKN_SMOKE_APK or install it first.`,
    );
    error.code = 2;
    throw error;
  }

  let smokeError;
  let networkRestoreError;
  try {
    run('01-auth-course.yaml', coreSelectors);
    run('02-checkout-cancel.yaml', coreSelectors);
    setNetwork(false);
    run('03-playback-offline.yaml', coreSelectors);
    run('04-project-queue-offline.yaml', coreSelectors);
    setNetwork(true);
    run('05-reconnect.yaml', coreSelectors);
    run('07-account-deletion.yaml', coreSelectors);
    await runForcedUpdateFlow();
  } catch (error) {
    smokeError = error;
  } finally {
    try {
      setNetwork(true);
    } catch (error) {
      networkRestoreError = error;
    }
  }
  if (smokeError && networkRestoreError) {
    throw new AggregateError(
      [smokeError, networkRestoreError],
      'Staging smoke and mandatory network restoration both failed.',
    );
  }
  if (networkRestoreError) throw networkRestoreError;
  if (smokeError) throw smokeError;

  console.log(
    'Staging Android release smoke passed. Preserve the Maestro report with the verified artifact provenance file.',
  );
};

if (require.main === module) {
  main().catch(error => {
    console.error(error.message);
    if (error instanceof AggregateError) {
      error.errors.forEach(item => console.error(`- ${item.message}`));
    }
    console.error(
      'See e2e/maestro/README.md. Do not mark this release as smoke-tested.',
    );
    process.exitCode = Number.isInteger(error.code) ? error.code : 1;
  });
}

module.exports = {
  activateForcedUpdate,
  deactivateForcedUpdate,
  failForMissing,
  fixtureUrl,
};
