'use strict';

const fs = require('fs');
const path = require('path');
const {spawnSync} = require('child_process');

const root = path.resolve(__dirname, '..');
const lock = JSON.parse(
  fs.readFileSync(path.join(root, 'package-lock.json'), 'utf8'),
);
const REVIEWED_MODERATE = {
  '@react-navigation/core': '7.13.7',
  '@react-navigation/elements': '2.9.3',
  '@react-navigation/native': '7.1.26',
  '@react-navigation/native-stack': '7.9.0',
  'decode-uri-component': '0.2.2',
  'query-string': '7.1.3',
};
const REVIEWED_MODERATE_ADVISORIES = new Set([
  'https://github.com/advisories/GHSA-vcc3-ghjq-m6fr',
]);
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
const advisoriesFor = (name, seen = new Set()) => {
  if (seen.has(name)) return new Set();
  seen.add(name);
  const urls = new Set();
  for (const via of vulnerabilities[name]?.via || []) {
    if (typeof via === 'string') {
      for (const url of advisoriesFor(via, seen)) urls.add(url);
    } else if (via?.url) {
      urls.add(via.url);
    }
  }
  return urls;
};
const installedVersion = name =>
  lock.packages?.[`node_modules/${name}`]?.version || '';
const reviewedModerate = [];
const unmitigated = [];
for (const [name, entry] of Object.entries(vulnerabilities)) {
  if (['high', 'critical'].includes(entry.severity)) {
    unmitigated.push(name);
    continue;
  }
  if (entry.severity !== 'moderate') continue;
  const advisoryUrls = advisoriesFor(name);
  const reviewed = REVIEWED_MODERATE[name] === installedVersion(name)
    && advisoryUrls.size > 0
    && [...advisoryUrls].every(url => REVIEWED_MODERATE_ADVISORIES.has(url));
  if (reviewed) reviewedModerate.push(`${name}@${installedVersion(name)}`);
  else unmitigated.push(name);
}
if (unmitigated.length) {
  console.error(
    `Unmitigated production dependency advisories: ${unmitigated.join(', ')}`,
  );
  process.exit(1);
}

console.log(
  `Dependency audit passed; reviewed moderate closure: ${reviewedModerate.join(', ') || 'none'}.`,
);
