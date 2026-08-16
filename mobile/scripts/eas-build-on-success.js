'use strict';

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const {spawnSync} = require('child_process');

if (process.env.EAS_BUILD_PLATFORM !== 'android') {
  console.log('No Android release evidence is required for this EAS build.');
  process.exit(0);
}
if (process.env.EAS_BUILD_PROFILE !== 'production-play') {
  console.log('Skipping production evidence for a non-Play Android profile.');
  process.exit(0);
}

const root = path.resolve(__dirname, '..');
const app = JSON.parse(
  fs.readFileSync(path.join(root, 'app.json'), 'utf8'),
).expo;
const outputRoot = path.join(root, 'android', 'app', 'build', 'outputs');
const collect = (directory, extension, found = []) => {
  if (!fs.existsSync(directory)) return found;
  for (const entry of fs.readdirSync(directory, {withFileTypes: true})) {
    const absolute = path.join(directory, entry.name);
    if (entry.isDirectory()) collect(absolute, extension, found);
    else if (entry.name.endsWith(extension)) found.push(absolute);
  }
  return found;
};
const bundles = collect(outputRoot, '.aab').sort(
  (left, right) => fs.statSync(right).mtimeMs - fs.statSync(left).mtimeMs,
);
if (bundles.length !== 1) {
  throw new Error(
    `Expected exactly one EAS Android App Bundle, found ${bundles.length}.`,
  );
}

const artifact = bundles[0];
const sourceMap = path.join(
  root,
  'android',
  'app',
  'build',
  'generated',
  'sourcemaps',
  'react',
  'release',
  'index.android.bundle.map',
);
const r8Mapping = path.join(
  root,
  'android',
  'app',
  'build',
  'outputs',
  'mapping',
  'release',
  'mapping.txt',
);
for (const required of [sourceMap, r8Mapping]) {
  if (!fs.existsSync(required) || fs.statSync(required).size === 0) {
    throw new Error(`EAS production build is missing release evidence: ${required}`);
  }
}

const certificate = spawnSync(
  'keytool',
  ['-printcert', '-jarfile', artifact],
  {encoding: 'utf8'},
);
if (certificate.status !== 0) {
  throw new Error(
    `Unable to inspect EAS AAB signer: ${certificate.stderr || certificate.stdout}`,
  );
}
const signerMatch = String(certificate.stdout).match(
  /^\s*SHA256:\s*([0-9a-f:]+)\s*$/im,
);
if (!signerMatch) throw new Error('EAS AAB signer SHA-256 is missing.');

const gitCommit = String(process.env.EAS_BUILD_GIT_COMMIT_HASH || '');
if (!/^[0-9a-f]{40}$/i.test(gitCommit)) {
  throw new Error('EAS_BUILD_GIT_COMMIT_HASH is missing or invalid.');
}
const apiBase = String(process.env.EXPO_PUBLIC_API_URL || '');
if (apiBase !== 'https://rokn.app/api/') {
  throw new Error('EAS production build has the wrong API base.');
}
const sha256 = value =>
  crypto.createHash('sha256').update(value).digest('hex');
const digest = sha256(fs.readFileSync(artifact));
const evidenceRoot = path.join(root, 'artifacts', 'eas');
const symbols = path.join(evidenceRoot, 'symbols');
fs.mkdirSync(symbols, {recursive: true});
fs.copyFileSync(sourceMap, path.join(symbols, 'index.android.bundle.map'));
fs.copyFileSync(r8Mapping, path.join(symbols, 'mapping.txt'));

const evidence = {
  name: path.basename(artifact),
  version: String(app.version),
  versionCode: Number(app.android.versionCode),
  channel: 'play',
  profile: 'production',
  format: 'aab',
  sha256: digest,
  bytes: fs.statSync(artifact).size,
  signerSha256: signerMatch[1].replace(/:/g, '').toLowerCase(),
  apiHost: 'rokn.app',
  apiBase,
  apiBaseSha256: sha256(apiBase),
  apiPathHash: sha256(new URL(apiBase).pathname),
  apiSource: 'eas-production-play',
  gitCommit: gitCommit.toLowerCase(),
  gitDirty: false,
  easBuildId: String(process.env.EAS_BUILD_ID || ''),
  builtAtUtc: new Date().toISOString(),
};
fs.writeFileSync(
  path.join(evidenceRoot, `${path.basename(artifact)}.json`),
  `${JSON.stringify(evidence, null, 2)}\n`,
  'utf8',
);
console.log(
  JSON.stringify(
    {artifact: evidence.name, sha256: evidence.sha256, gitCommit},
    null,
    2,
  ),
);
