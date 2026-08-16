'use strict';

const fs = require('fs');
const path = require('path');
const {spawnSync} = require('child_process');
const {
  applyMetroImageParserPolicy,
  BLOCKED_METRO_ASSET_EXTENSIONS,
  DISABLED_IMAGE_TYPES,
} = require('./metro-image-parser-policy');

const root = path.resolve(__dirname, '..');
const npmCli = process.env.npm_execpath;
if (!npmCli || !fs.existsSync(npmCli)) {
  console.error('npm audit must run through npm so its verified CLI path is available.');
  process.exit(1);
}
const audit = spawnSync(
  process.execPath,
  [npmCli, 'audit', '--omit=dev', '--audit-level=high', '--json'],
  {cwd: root, encoding: 'utf8', maxBuffer: 20 * 1024 * 1024},
);

if (audit.error || audit.status === null || audit.status > 1) {
  console.error(audit.stderr || audit.error || 'npm audit could not run.');
  process.exit(1);
}

let report;
try {
  report = JSON.parse(audit.stdout || '{}');
} catch {
  console.error('npm audit returned malformed JSON.');
  process.exit(1);
}

const vulnerabilities = report.vulnerabilities || {};
const allowedAdvisories = new Set([
  'https://github.com/advisories/GHSA-w3rx-r6r6-pgpr',
  'https://github.com/advisories/GHSA-5p2g-fcmc-qvqq',
]);
const expectedDisabledTypes = ['heif', 'icns', 'jxl', 'jxl-stream'];
const expectedBlockedExtensions = ['heic', 'heif', 'icns', 'jxl'];
const mitigationReviewDeadline = Date.parse('2026-09-15T00:00:00Z');
const imageSizePackage = JSON.parse(
  fs.readFileSync(require.resolve('image-size/package.json'), 'utf8'),
);
const metroConfig = require(path.join(root, 'metro.config.js'));
const metroAssetExtensions = new Set(
  (metroConfig.resolver?.assetExts || []).map(extension =>
    String(extension).toLowerCase(),
  ),
);

const mitigationIsCurrent =
  Date.now() < mitigationReviewDeadline &&
  imageSizePackage.version === '1.2.1' &&
  expectedDisabledTypes.every(type => DISABLED_IMAGE_TYPES.includes(type)) &&
  DISABLED_IMAGE_TYPES.every(type => expectedDisabledTypes.includes(type)) &&
  expectedBlockedExtensions.every(extension =>
    BLOCKED_METRO_ASSET_EXTENSIONS.includes(extension),
  ) &&
  BLOCKED_METRO_ASSET_EXTENSIONS.every(extension =>
    expectedBlockedExtensions.includes(extension),
  ) &&
  expectedBlockedExtensions.every(extension =>
    !metroAssetExtensions.has(extension),
  );

if (mitigationIsCurrent) {
  // Fail here if a future dependency update removes the API instead of
  // silently treating the advisory as accepted.
  applyMetroImageParserPolicy();
}

const auditChain = (name, visiting = new Set()) => {
  if (visiting.has(name)) {
    return {valid: true, advisoryUrls: new Set()};
  }
  const entry = vulnerabilities[name];
  if (!entry) return {valid: false, advisoryUrls: new Set()};

  const nextVisiting = new Set(visiting).add(name);
  const advisoryUrls = new Set();
  let valid = true;
  for (const cause of entry.via || []) {
    if (typeof cause === 'string') {
      const nested = auditChain(cause, nextVisiting);
      valid = valid && nested.valid;
      nested.advisoryUrls.forEach(url => advisoryUrls.add(url));
    } else if (cause && typeof cause === 'object' && cause.url) {
      advisoryUrls.add(cause.url);
    } else {
      valid = false;
    }
  }
  return {valid, advisoryUrls};
};

const isMitigatedChain = name => {
  const chain = auditChain(name);
  return (
    mitigationIsCurrent &&
    chain.valid &&
    chain.advisoryUrls.size === allowedAdvisories.size &&
    [...chain.advisoryUrls].every(url => allowedAdvisories.has(url))
  );
};

const unmitigated = Object.keys(vulnerabilities).filter(
  name => !isMitigatedChain(name),
);
if (unmitigated.length) {
  console.error(
    `Unmitigated production dependency advisories: ${unmitigated.join(', ')}`,
  );
  process.exit(1);
}

if (Object.keys(vulnerabilities).length) {
  console.log(
    'Dependency audit passed with the pinned Metro image parser mitigation; no runtime advisory remains unreviewed.',
  );
} else {
  console.log('Dependency audit passed with no high-severity advisories.');
}
