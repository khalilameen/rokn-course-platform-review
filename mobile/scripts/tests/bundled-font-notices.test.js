'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const {
  LICENSE_SHA256,
  buildBundledFontInventory,
  renderBundledFontMarkdown,
} = require('../bundled-font-notices');

const root = path.resolve(__dirname, '../..');

test('Cairo files shipped by Android and iOS are hash-bound to the full OFL notice', () => {
  const inventory = buildBundledFontInventory(root);
  assert.equal(inventory.files.length, 8);
  assert.equal(inventory.license, 'OFL-1.1');
  assert.equal(inventory.licenseSha256, LICENSE_SHA256);
  const markdown = renderBundledFontMarkdown(inventory);
  assert.match(markdown, /Copyright 2009 The Cairo Project Authors/);
  const shippedNotice = fs.readFileSync(
    path.join(
      root,
      'android',
      'app',
      'src',
      'main',
      'assets',
      'NATIVE_THIRD_PARTY_NOTICES.md',
    ),
    'utf8',
  );
  inventory.files.forEach(file => {
    assert.match(markdown, new RegExp(file.sha256));
    assert.match(shippedNotice, new RegExp(file.sha256));
  });
  const appData = JSON.parse(
    fs.readFileSync(
      path.join(root, 'src', 'data', 'nativeThirdPartyNotices.generated.json'),
      'utf8',
    ),
  );
  assert.deepEqual(appData.bundledAssets[0].files, inventory.files);
});

test('bundled font gate rejects changed distributed bytes', () => {
  const fixture = fs.mkdtempSync(path.join(os.tmpdir(), 'rokn-fonts-'));
  try {
    const copies = [
      ['src/assets/fonts/Cairo', 'src/assets/fonts/Cairo'],
      [
        'android/app/src/main/assets/fonts',
        'android/app/src/main/assets/fonts',
      ],
      [
        'ios/Rokn.xcodeproj/project.pbxproj',
        'ios/Rokn.xcodeproj/project.pbxproj',
      ],
      ['ios/Rokn/Info.plist', 'ios/Rokn/Info.plist'],
      ['react-native.config.js', 'react-native.config.js'],
    ];
    for (const [source, destination] of copies) {
      const target = path.join(fixture, destination);
      fs.mkdirSync(path.dirname(target), {recursive: true});
      fs.cpSync(path.join(root, source), target, {recursive: true});
    }
    fs.appendFileSync(
      path.join(fixture, 'android/app/src/main/assets/fonts/Cairo-Regular.ttf'),
      'tamper',
    );
    assert.throws(
      () => buildBundledFontInventory(fixture),
      /Bundled Cairo bytes changed without review/,
    );
  } finally {
    fs.rmSync(fixture, {recursive: true, force: true});
  }
});
