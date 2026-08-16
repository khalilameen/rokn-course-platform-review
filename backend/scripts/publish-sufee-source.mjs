import { copyFileSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const sourceRoot = resolve(root, 'resources/vendor/sufee');
const publicRoot = resolve(root, 'public/legal/source/sufee-1.0.0-rokn');
const sourceFiles = [
    'style.scss',
    '_variables.scss',
    '_gauge.scss',
    '_switches.scss',
    '_widgets.scss',
    'COPYING.GPL-2.0.txt',
    'LICENSE.upstream-MIT.txt',
];

function sha256(path) {
    return createHash('sha256').update(readFileSync(path)).digest('hex');
}

mkdirSync(publicRoot, { recursive: true });
for (const file of sourceFiles) {
    copyFileSync(resolve(sourceRoot, file), resolve(publicRoot, file));
}

const compiledCss = resolve(root, 'public/admin/assets/scss/style.css');
const manifest = {
    component: 'Sufee Admin Dashboard 1.0.0 (Rokn-modified)',
    upstream: 'https://github.com/puikinsh/sufee-admin-dashboard/tree/dcae40f7d2afea4fc0e8480fa4b3558ef4d2cc38',
    compiledArtifact: 'public/admin/assets/scss/style.css',
    compiledSha256: sha256(compiledCss),
    build: 'npm ci && npm run build:admin-vendor-css',
    correspondingSource: Object.fromEntries(sourceFiles.map(file => [file, sha256(resolve(sourceRoot, file))])),
};

writeFileSync(resolve(publicRoot, 'SOURCE_MANIFEST.json'), `${JSON.stringify(manifest, null, 2)}\n`, 'utf8');
writeFileSync(
    resolve(publicRoot, 'README.txt'),
    [
        'Sufee corresponding source for the CSS served by this deployment',
        '',
        'The exact preferred SCSS source, modification notes, and license texts are published in this directory.',
        'SOURCE_MANIFEST.json binds this source set to the SHA-256 of /admin/assets/scss/style.css.',
        'Rebuild from the repository root with: npm ci && npm run build:admin-vendor-css',
        'Modification details: removed unlicensed bundled fonts; use the Rokn system font stack; removed two missing decorative image URLs; retained animate.css through a route-safe absolute import.',
        '',
    ].join('\n'),
    'utf8',
);

console.log('Published hash-bound Sufee corresponding source under public/legal/source.');
