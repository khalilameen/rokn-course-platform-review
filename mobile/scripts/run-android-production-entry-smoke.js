'use strict';

/*
 * Exercises the public production entry journeys from the installed release
 * artifact. It intentionally needs no learner credentials: cold start, guest
 * catalogue, course details and the hand-off to every advertised OAuth
 * provider must work before a build is allowed to reach manual acceptance.
 */
const {existsSync, mkdtempSync, rmSync} = require('fs');
const {execFileSync, spawnSync} = require('child_process');
const {tmpdir} = require('os');
const path = require('path');

const appId = process.env.ROKN_SMOKE_APP_ID || 'com.rokn';
const serial = process.env.ANDROID_SERIAL || 'emulator-5554';
const timeoutMs = Number(process.env.ROKN_SMOKE_TIMEOUT_MS || 25_000);
const apk = process.env.ROKN_SMOKE_APK;
const remoteDump = '/sdcard/rokn-production-entry.xml';
const workingDirectory = mkdtempSync(path.join(tmpdir(), 'rokn-entry-smoke-'));

const fail = message => {
  const error = new Error(message);
  error.code = 1;
  throw error;
};

const findAdb = () => {
  const configured = String(process.env.ROKN_ADB || '').trim();
  if (configured) {
    if (!existsSync(configured)) fail(`ROKN_ADB does not exist: ${configured}`);
    return configured;
  }
  const resolver = process.platform === 'win32' ? 'where' : 'which';
  const result = spawnSync(resolver, ['adb'], {encoding: 'utf8'});
  const candidate = String(result.stdout || '').split(/\r?\n/).find(Boolean);
  if (!candidate) fail('adb was not found. Set ROKN_ADB to platform-tools/adb.');
  return candidate.trim();
};

const adbBinary = findAdb();
const adb = (args, options = {}) =>
  execFileSync(adbBinary, ['-s', serial, ...args], {
    encoding: 'utf8',
    stdio: options.silent ? ['ignore', 'pipe', 'pipe'] : ['ignore', 'pipe', 'pipe'],
    timeout: options.timeout || 30_000,
  });

const delay = milliseconds =>
  new Promise(resolve => setTimeout(resolve, milliseconds));

const decodeXml = value =>
  value
    .replaceAll('&quot;', '"')
    .replaceAll('&apos;', "'")
    .replaceAll('&lt;', '<')
    .replaceAll('&gt;', '>')
    .replaceAll('&amp;', '&')
    .replaceAll('&#10;', '\n');

const nodesFromXml = xml =>
  [...xml.matchAll(/<node\s+([^>]+?)(?:\s*\/?>)/g)].map(match => {
    const attributes = {};
    for (const attribute of match[1].matchAll(/([\w-]+)="([^"]*)"/g)) {
      attributes[attribute[1]] = decodeXml(attribute[2]);
    }
    return attributes;
  });

const dumpUi = () => {
  adb(['shell', 'uiautomator', 'dump', remoteDump], {silent: true});
  return adb(['exec-out', 'cat', remoteDump], {silent: true});
};

const waitForUi = async predicate => {
  const deadline = Date.now() + timeoutMs;
  let lastXml = '';
  while (Date.now() < deadline) {
    try {
      lastXml = dumpUi();
      const nodes = nodesFromXml(lastXml);
      const match = nodes.find(predicate);
      if (match) return {match, nodes, xml: lastXml};
    } catch {
      // React Native can be between frames while the hierarchy is requested.
    }
    await delay(700);
  }
  fail(`Timed out waiting for the expected production UI. Last UI: ${lastXml.slice(0, 800)}`);
};

const textOrDescription = (node, value) =>
  node.text === value || node['content-desc'] === value;

const boundsCenter = rawBounds => {
  const match = String(rawBounds || '').match(
    /^\[(\d+),(\d+)\]\[(\d+),(\d+)\]$/,
  );
  if (!match) fail(`Node has invalid bounds: ${rawBounds}`);
  const [, left, top, right, bottom] = match.map(Number);
  return [Math.round((left + right) / 2), Math.round((top + bottom) / 2)];
};

const tapNode = node => {
  const [x, y] = boundsCenter(node.bounds);
  adb(['shell', 'input', 'tap', String(x), String(y)]);
};

const tapLabel = async label => {
  const {match} = await waitForUi(node => textOrDescription(node, label));
  tapNode(match);
};

const assertNoPublicBlocker = nodes => {
  const blocker = nodes.find(node =>
    /تعذّر تحميل|انتهت محاولة الدخول|حدث خطأ غير متوقع/.test(
      `${node.text || ''} ${node['content-desc'] || ''}`,
    ),
  );
  if (blocker) fail(`Production UI displayed a blocker: ${blocker.text || blocker['content-desc']}`);
};

const launchFresh = async () => {
  adb(['shell', 'pm', 'clear', appId]);
  adb([
    'shell',
    'monkey',
    '-p',
    appId,
    '-c',
    'android.intent.category.LAUNCHER',
    '1',
  ]);
  return waitForUi(node => textOrDescription(node, 'المتابعة كزائر'));
};

const verifyGuestJourney = async () => {
  await launchFresh();
  await tapLabel('المتابعة كزائر');
  const home = await waitForUi(node => node['content-desc'] === 'الرئيسية');
  assertNoPublicBlocker(home.nodes);

  const courseAction = home.nodes.find(
    node =>
      node.class === 'android.widget.Button' &&
      /^عرض\s+\S/.test(node['content-desc'] || ''),
  );
  if (!courseAction) fail('Production home has no openable published course.');
  const courseTitle = courseAction['content-desc'].replace(/^عرض\s+/, '').trim();
  tapNode(courseAction);

  const details = await waitForUi(
    node =>
      node.text === courseTitle &&
      node.class === 'android.widget.TextView',
  );
  assertNoPublicBlocker(details.nodes);
  if (!details.nodes.some(node => /^[٠-٩0-9]+\s+دقيقة$/.test(node.text || ''))) {
    fail('Guest course details did not expose the course duration.');
  }
  if (!details.nodes.some(node => node['content-desc'] === 'خريطة الكورس')) {
    fail('Guest course details did not expose the course map.');
  }
  process.stdout.write(`PASS guest > home > course details (${courseTitle})\n`);
};

const topActivity = () => adb(['shell', 'dumpsys', 'activity', 'activities']);

const verifyProviderHandoff = async ({label, expectedDomain, finalProvider = false}) => {
  await tapLabel(label);
  const deadline = Date.now() + timeoutMs;
  let xml = '';
  while (Date.now() < deadline) {
    const activities = topActivity();
    if (/topResumedActivity=.*com\.android\.chrome/.test(activities)) {
      xml = dumpUi();
      if (xml.includes(expectedDomain)) break;
    }
    await delay(700);
  }
  if (!xml.includes(expectedDomain)) {
    fail(`${label} did not reach ${expectedDomain} in the Android browser.`);
  }
  process.stdout.write(`PASS ${label} > ${expectedDomain}\n`);
  adb(['shell', 'input', 'keyevent', '4']);
  const returned = await waitForUi(node =>
    textOrDescription(node, label) ||
    (finalProvider && node['content-desc'] === 'الرئيسية'),
  );
  assertNoPublicBlocker(returned.nodes);
};

const verifyAuthEntry = async () => {
  await launchFresh();
  await tapLabel('تسجيل الدخول');
  const auth = await waitForUi(node => textOrDescription(node, 'المتابعة بحساب Google'));
  assertNoPublicBlocker(auth.nodes);
  if (!auth.nodes.some(node => textOrDescription(node, 'المتابعة بحساب TikTok'))) {
    fail('TikTok is advertised by production but missing from the login screen.');
  }
  await verifyProviderHandoff({
    label: 'المتابعة بحساب Google',
    expectedDomain: 'accounts.google.com',
  });
  await verifyProviderHandoff({
    label: 'المتابعة بحساب TikTok',
    expectedDomain: 'tiktok.com',
    finalProvider: true,
  });
};

const main = async () => {
  try {
    adb(['get-state']);
    if (apk) {
      if (!existsSync(apk)) fail(`ROKN_SMOKE_APK does not exist: ${apk}`);
      adb(['install', '-r', apk], {timeout: 120_000});
    }
    if (!adb(['shell', 'pm', 'path', appId]).includes('package:')) {
      fail(`${appId} is not installed. Set ROKN_SMOKE_APK or install it first.`);
    }
    await verifyGuestJourney();
    await verifyAuthEntry();
    process.stdout.write('Production Android entry smoke passed.\n');
  } finally {
    rmSync(workingDirectory, {recursive: true, force: true});
  }
};

if (require.main === module) {
  main().catch(error => {
    console.error(error.message);
    console.error('Do not hand off this APK as production-entry tested.');
    process.exitCode = Number.isInteger(error.code) ? error.code : 1;
  });
}

module.exports = {boundsCenter, decodeXml, nodesFromXml};
