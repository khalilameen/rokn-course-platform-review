'use strict';

const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.resolve(__dirname, '..');
const LICENSE_SHA256 =
  'aa2b13bf0770c59a7fb7a3b793a7df5162c9cb5ad540ec81b0c0c1385946845d';
const FONT_SHA256 = new Map([
  [
    'Cairo-Black.ttf',
    '34880d805ad84bb9c195becc1379b89986ee1efc8f7858e276234c04f4de63d9',
  ],
  [
    'Cairo-Bold.ttf',
    'a0e58d71b85b15902ea87914d8e31a6d22da48ac2db70213dcfd1a7dad3f198a',
  ],
  [
    'Cairo-ExtraBold.ttf',
    '67526b4f18bb9bb568e81135df9eec813a15cc1252ae784ea505236e75a5b56e',
  ],
  [
    'Cairo-ExtraLight.ttf',
    '578f271954ad8b4a49459b07255dcf572025daa0045f3364ce738618d5da81cc',
  ],
  [
    'Cairo-Light.ttf',
    'c137f678a3c8b1f12e730fa58f4ddb5334e78719528015765478c9c2de603d57',
  ],
  [
    'Cairo-Medium.ttf',
    '64c7cab8e2cf003c3fc4edca1820e7e1b0ee667373cf8500060bca97262a2b65',
  ],
  [
    'Cairo-Regular.ttf',
    '44786a38e27c58262cbd65341beda4fa4f6c7085ec42e830b35ba1ae37807030',
  ],
  [
    'Cairo-SemiBold.ttf',
    '3fe2e182a88a5d64a96b289fd1c518bdc9b14ec91525a03990c35e91f7863a2a',
  ],
]);

const sha256 = value => crypto.createHash('sha256').update(value).digest('hex');

const exactFileNames = directory =>
  fs
    .readdirSync(directory, {withFileTypes: true})
    .filter(entry => entry.isFile())
    .map(entry => entry.name)
    .sort();

const buildBundledFontInventory = (root = ROOT) => {
  const sourceDirectory = path.join(root, 'src', 'assets', 'fonts', 'Cairo');
  const androidDirectory = path.join(
    root,
    'android',
    'app',
    'src',
    'main',
    'assets',
    'fonts',
  );
  const expectedSourceFiles = [...FONT_SHA256.keys(), 'OFL.txt'].sort();
  if (
    JSON.stringify(exactFileNames(sourceDirectory)) !==
    JSON.stringify(expectedSourceFiles)
  ) {
    throw new Error('Cairo source inventory changed without a legal review.');
  }
  if (
    JSON.stringify(
      exactFileNames(androidDirectory).filter(name => /\.ttf$/i.test(name)),
    ) !== JSON.stringify([...FONT_SHA256.keys()].sort())
  ) {
    throw new Error(
      'Android bundled Cairo inventory is incomplete or contains extra fonts.',
    );
  }

  const files = [...FONT_SHA256].map(([name, expectedSha256]) => {
    const sourceBytes = fs.readFileSync(path.join(sourceDirectory, name));
    const androidBytes = fs.readFileSync(path.join(androidDirectory, name));
    const actualSha256 = sha256(sourceBytes);
    if (
      actualSha256 !== expectedSha256 ||
      sha256(androidBytes) !== expectedSha256
    ) {
      throw new Error(`Bundled Cairo bytes changed without review: ${name}.`);
    }
    return {name, sha256: actualSha256, size: sourceBytes.length};
  });
  const licenseText = fs.readFileSync(
    path.join(sourceDirectory, 'OFL.txt'),
    'utf8',
  );
  if (
    sha256(Buffer.from(licenseText, 'utf8')) !== LICENSE_SHA256 ||
    !licenseText.includes('SIL OPEN FONT LICENSE Version 1.1') ||
    !licenseText.includes('Copyright 2009 The Cairo Project Authors')
  ) {
    throw new Error('Cairo OFL.txt changed or is incomplete.');
  }

  const project = fs.readFileSync(
    path.join(root, 'ios', 'Rokn.xcodeproj', 'project.pbxproj'),
    'utf8',
  );
  const info = fs.readFileSync(
    path.join(root, 'ios', 'Rokn', 'Info.plist'),
    'utf8',
  );
  for (const file of files) {
    if (
      !project.includes(`${file.name} in Resources`) ||
      !project.includes(`../src/assets/fonts/Cairo/${file.name}`) ||
      !info.includes(`<string>${file.name}</string>`)
    ) {
      throw new Error(
        `iOS does not bind the reviewed Cairo file: ${file.name}.`,
      );
    }
  }
  const reactNativeConfig = fs.readFileSync(
    path.join(root, 'react-native.config.js'),
    'utf8',
  );
  if (!reactNativeConfig.includes("assets: ['./src/assets/fonts/Cairo']")) {
    throw new Error(
      'React Native font linking is not restricted to reviewed Cairo assets.',
    );
  }

  return {
    coordinate: 'font:Cairo',
    family: 'Cairo',
    license: 'OFL-1.1',
    licenseSha256: LICENSE_SHA256,
    licenseText: licenseText.replace(/\r\n?/g, '\n').trim(),
    sourceUrl: 'https://github.com/Gue3bara/Cairo',
    files,
  };
};

const renderBundledFontMarkdown = inventory =>
  [
    '## App-bundled font assets',
    '',
    `### ${inventory.family}`,
    '',
    `- License: \`${inventory.license}\``,
    `- Upstream source: ${inventory.sourceUrl}`,
    `- License SHA-256: \`${inventory.licenseSha256}\``,
    '- Distributed font files:',
    ...inventory.files.map(
      file =>
        `  - \`${file.name}\` — SHA-256 \`${file.sha256}\` (${file.size} bytes)`,
    ),
    '',
    '```text',
    inventory.licenseText.replace(/```/g, '` ` `'),
    '```',
    '',
  ].join('\n');

if (require.main === module) {
  try {
    const inventory = buildBundledFontInventory();
    console.log(
      `Bundled font gate passed for ${inventory.files.length} Cairo files under ${inventory.license}.`,
    );
  } catch (error) {
    console.error(error instanceof Error ? error.message : String(error));
    process.exit(1);
  }
}

module.exports = {
  FONT_SHA256,
  LICENSE_SHA256,
  buildBundledFontInventory,
  renderBundledFontMarkdown,
};
