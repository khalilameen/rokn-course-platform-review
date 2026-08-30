'use strict';

const fs = require('node:fs');
const path = require('node:path');

const MOBILE_ROOT = path.resolve(__dirname, '..');
const SUPPORTED_VERSION = '26.3.2';
const UPSTREAM_FIX =
  'https://github.com/invertase/react-native-firebase/pull/9225';

const fixes = [
  {
    packageName: '@react-native-firebase/app',
    source: 'ios/RNFBApp/RNFBAppModule.mm',
    symbol: 'NativeRNFBTurboApp',
  },
  {
    packageName: '@react-native-firebase/app',
    source: 'ios/RNFBApp/RNFBUtilsModule.mm',
    symbol: 'NativeRNFBTurboUtils',
  },
  {
    packageName: '@react-native-firebase/messaging',
    source: 'ios/RNFBMessaging/RNFBMessagingModule.mm',
    symbol: 'NativeRNFBTurboMessaging',
  },
];

const occurrences = (contents, fragment) => contents.split(fragment).length - 1;

function applyFixes({root = MOBILE_ROOT, check = false} = {}) {
  for (const fix of fixes) {
    const packageRoot = path.join(root, 'node_modules', ...fix.packageName.split('/'));
    const packageJsonPath = path.join(packageRoot, 'package.json');
    const installedVersion = JSON.parse(
      fs.readFileSync(packageJsonPath, 'utf8'),
    ).version;
    if (installedVersion !== SUPPORTED_VERSION) {
      throw new Error(
        `${fix.packageName} ${installedVersion} is not the audited ${SUPPORTED_VERSION}; ` +
          `remove or re-audit the temporary upstream compatibility fix.`,
      );
    }

    const sourcePath = path.join(packageRoot, ...fix.source.split('/'));
    const before = `ModuleConstants<JS::${fix.symbol}::Constants::Builder>`;
    const after = `ModuleConstants<JS::${fix.symbol}::Constants>`;
    const contents = fs.readFileSync(sourcePath, 'utf8');
    const beforeCount = occurrences(contents, before);
    const afterCount = occurrences(contents, after);

    if (beforeCount === 0 && afterCount === 2) {
      continue;
    }
    if (beforeCount !== 2 || afterCount !== 0) {
      throw new Error(
        `Unexpected ${fix.packageName} source shape in ${fix.source}; refusing a partial patch.`,
      );
    }
    if (check) {
      throw new Error(
        `${fix.packageName}/${fix.source} still needs the audited iOS constants fix.`,
      );
    }

    fs.writeFileSync(sourcePath, contents.split(before).join(after), 'utf8');
  }

  return {files: fixes.length, version: SUPPORTED_VERSION, upstream: UPSTREAM_FIX};
}

if (require.main === module) {
  const result = applyFixes({check: process.argv.includes('--check')});
  console.log(
    `React Native Firebase iOS compatibility gate passed for ${result.files} files ` +
      `(v${result.version}, upstream #9225).`,
  );
}

module.exports = {SUPPORTED_VERSION, UPSTREAM_FIX, applyFixes, fixes};
