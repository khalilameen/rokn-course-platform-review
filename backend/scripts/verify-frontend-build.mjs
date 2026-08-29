import { existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const artifacts = [
    'public/js/app.js',
    'public/js/app.js.LEGAL.txt',
    'public/css/app.css',
    'public/THIRD_PARTY_NOTICES.frontend.md',
];

const failures = [];

for (const artifact of artifacts) {
    if (!existsSync(resolve(repositoryRoot, artifact))) {
        failures.push(`${artifact} was not produced.`);
        continue;
    }

    const tracked = spawnSync(
        'git',
        ['ls-files', '--error-unmatch', '--', artifact],
        { cwd: repositoryRoot, encoding: 'utf8' },
    );

    if (tracked.status !== 0) {
        failures.push(`${artifact} is not tracked by Git.`);
    }
}

const status = spawnSync(
    'git',
    ['status', '--porcelain=v1', '--untracked-files=all', '--', ...artifacts],
    { cwd: repositoryRoot, encoding: 'utf8' },
);

if (status.error || status.status !== 0) {
    failures.push('Git could not verify the generated frontend artifacts.');
} else if (status.stdout.trim() !== '') {
    const changedArtifacts = status.stdout.trim().split(/\r?\n/).join(', ');
    const summary = spawnSync(
        'git',
        ['diff', '--numstat', '--summary', '--', ...artifacts],
        { cwd: repositoryRoot, encoding: 'utf8' },
    );
    const changeSummary = summary.status === 0 && summary.stdout.trim() !== ''
        ? ` Changed lines: ${summary.stdout.trim().split(/\r?\n/).join('; ')}.`
        : '';
    failures.push(
        `The committed frontend artifacts do not match the production build: ${changedArtifacts}.${changeSummary}`,
    );
}

if (failures.length > 0) {
    for (const failure of failures) {
        console.error(`Frontend build verification failed: ${failure}`);
    }

    console.error('Run `npm run production`, then commit every generated frontend artifact.');
    process.exit(1);
}

console.log('Frontend build artifacts are present, tracked, and reproducible.');
