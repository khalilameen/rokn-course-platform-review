'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const ROOT = path.resolve(__dirname, '..', '..');
const {
  validateAutolinkedPublications,
  validateGradleDeclarations,
  validateLock,
  validateMetadata,
  validateRoot,
} = require('../verify-gradle-dependency-provenance');

test('Gradle plugins and release dependencies are hash-verified and locked', () => {
  const result = validateRoot(ROOT);
  assert.ok(result.componentCount >= 500);
  assert.ok(result.artifactCount >= 500);
  assert.ok(result.checksumCount >= result.artifactCount);
  assert.ok(result.buildscriptLockCount > 100);
  assert.ok(result.appLockCount > 150);
  assert.ok(result.autolinkedPublicationCount > 5);
});

test('Gradle provenance gate rejects missing hashes and dynamic lock versions', () => {
  const metadata = fs.readFileSync(
    path.join(ROOT, 'android', 'gradle', 'verification-metadata.xml'),
    'utf8',
  );
  assert.throws(
    () =>
      validateMetadata(
        metadata.replace(/<sha256 value="[0-9a-f]{64}"[^>]*\/>/, ''),
      ),
    /no reviewed SHA-256 checksum/,
  );
  assert.throws(
    () =>
      validateLock(
        '# This is a Gradle generated file for dependency locking.\nexample:unsafe:1.+=releaseRuntimeClasspath\n',
        'fixture.lockfile',
      ),
    /dynamic dependency version/,
  );
  assert.throws(
    () =>
      validateGradleDeclarations(
        "dependencies { implementation('example:unsafe:1.+') }",
        'fixture.gradle',
      ),
    /declares a dynamic dependency version/,
  );
  assert.throws(
    () =>
      validateAutolinkedPublications('', {
        modules: [
          {
            projects: [
              {
                publication: {
                  artifactId: 'expo.modules.fixture',
                  groupId: 'host.exp.exponent',
                  version: '55.0.99',
                },
              },
            ],
          },
        ],
      }),
    /lockfile is stale for autolinked Android publication/,
  );
});
