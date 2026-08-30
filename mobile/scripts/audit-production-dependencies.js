'use strict';

const fs = require('fs');
const path = require('path');
const {spawnSync} = require('child_process');

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
const unmitigated = Object.entries(vulnerabilities)
  .filter(([, entry]) => ['high', 'critical'].includes(entry.severity))
  .map(([name]) => name);
if (unmitigated.length) {
  console.error(
    `Unmitigated production dependency advisories: ${unmitigated.join(', ')}`,
  );
  process.exit(1);
}

console.log('Dependency audit passed with no high-severity advisories.');
