'use strict';

const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.resolve(__dirname, '..');

const CRITICAL_COMPONENTS = [
  ['com.android.tools.build', 'gradle', '8.12.0'],
  ['com.google.gms', 'google-services', '4.5.0'],
  ['org.jetbrains.kotlin', 'kotlin-gradle-plugin', '2.1.20'],
  ['com.facebook.react', 'react-android', '0.83.10'],
  ['com.google.firebase', 'firebase-messaging', '25.0.1'],
];

const escapeRegExp = value => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

const validateMetadata = text => {
  if (
    !/<verify-metadata>true<\/verify-metadata>/.test(text) ||
    !/<components>[\s\S]*<\/components>/.test(text)
  ) {
    throw new Error(
      'Gradle dependency verification metadata is not strict and complete.',
    );
  }
  const components = text.match(/<component\s/g) || [];
  const artifacts = [
    ...text.matchAll(/<artifact\b[^>]*>([\s\S]*?)<\/artifact>/g),
  ];
  const checksums = text.match(/<sha256 value="[0-9a-f]{64}"/g) || [];
  if (components.length < 500 || artifacts.length < 500) {
    throw new Error('Gradle verification metadata is unexpectedly incomplete.');
  }
  for (const artifact of artifacts) {
    if (!/<sha256 value="[0-9a-f]{64}"/.test(artifact[1])) {
      throw new Error('A Gradle artifact has no reviewed SHA-256 checksum.');
    }
  }
  for (const [group, name, version] of CRITICAL_COMPONENTS) {
    const component = new RegExp(
      `<component group="${escapeRegExp(group)}" name="${escapeRegExp(
        name,
      )}" version="${escapeRegExp(version)}">`,
    );
    if (!component.test(text)) {
      throw new Error(
        `Critical Gradle component is not verified: ${group}:${name}:${version}.`,
      );
    }
  }
  return {
    artifactCount: artifacts.length,
    checksumCount: checksums.length,
    componentCount: components.length,
  };
};

const validateLock = (text, label, requiredCoordinates = []) => {
  if (
    !text.startsWith(
      '# This is a Gradle generated file for dependency locking.',
    )
  ) {
    throw new Error(`${label} is not a generated Gradle lockfile.`);
  }
  const lines = text
    .split(/\r?\n/)
    .map(line => line.trim())
    .filter(
      line => line && !line.startsWith('#') && !line.startsWith('empty='),
    );
  for (const line of lines) {
    const coordinate = line.split('=')[0];
    const version = coordinate.split(':').at(-1) || '';
    if (/\+|snapshot|latest|[[(]/i.test(version)) {
      throw new Error(
        `${label} contains a dynamic dependency version: ${coordinate}.`,
      );
    }
  }
  for (const coordinate of requiredCoordinates) {
    if (!lines.some(line => line.startsWith(`${coordinate}=`))) {
      throw new Error(`${label} is missing ${coordinate}.`);
    }
  }
  return lines.length;
};

const validateRoot = (root = ROOT) => {
  const read = relative => {
    const absolute = path.join(root, relative);
    if (!fs.existsSync(absolute)) throw new Error(`${relative} is missing.`);
    return fs.readFileSync(absolute, 'utf8');
  };
  const metadata = validateMetadata(
    read('android/gradle/verification-metadata.xml'),
  );
  const buildscriptCount = validateLock(
    read('android/buildscript-gradle.lockfile'),
    'android/buildscript-gradle.lockfile',
    [
      'com.android.tools.build:gradle:8.12.0',
      'com.google.gms:google-services:4.5.0',
      'org.jetbrains.kotlin:kotlin-gradle-plugin:2.1.20',
    ],
  );
  const appCount = validateLock(
    read('android/app/gradle.lockfile'),
    'android/app/gradle.lockfile',
    [
      'com.facebook.react:react-android:0.83.10',
      'com.google.firebase:firebase-messaging:25.0.1',
    ],
  );
  validateLock(
    read('android/settings-gradle.lockfile'),
    'android/settings-gradle.lockfile',
  );
  const buildGradle = read('android/build.gradle');
  if (
    !/activateDependencyLocking\(\)/.test(buildGradle) ||
    !/lockAllConfigurations\(\)/.test(buildGradle)
  ) {
    throw new Error('Android Gradle dependency locking is not activated.');
  }
  const wrapper = read('android/gradle/wrapper/gradle-wrapper.properties');
  if (!/^distributionSha256Sum=[0-9a-f]{64}$/m.test(wrapper)) {
    throw new Error('Gradle wrapper distribution has no SHA-256 pin.');
  }
  return {
    ...metadata,
    appLockCount: appCount,
    buildscriptLockCount: buildscriptCount,
  };
};

if (require.main === module) {
  try {
    const result = validateRoot();
    console.log(
      `Gradle dependency provenance gate passed for ${result.componentCount} verified components, ${result.artifactCount} artifacts, ${result.buildscriptLockCount} buildscript locks, and ${result.appLockCount} app locks.`,
    );
  } catch (error) {
    console.error(error instanceof Error ? error.message : String(error));
    process.exit(1);
  }
}

module.exports = {validateLock, validateMetadata, validateRoot};
