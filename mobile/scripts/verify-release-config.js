'use strict';

const fs = require('fs');
const path = require('path');
const {execFileSync} = require('child_process');

const root = path.resolve(__dirname, '..');
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');
const json = relative => JSON.parse(read(relative));
const failures = [];
const assert = (condition, message) => {
  if (!condition) failures.push(message);
};

const decodeXmlText = value =>
  value.replace(/&(amp|lt|gt|quot|apos);/g, (_match, entity) => {
    const entities = {amp: '&', lt: '<', gt: '>', quot: '"', apos: "'"};
    return entities[entity];
  });
const parseFlatPlist = contents => {
  const values = {};
  const entryPattern =
    /<key>([^<]+)<\/key>\s*<(string|true|false|integer|real)(?:>([\s\S]*?)<\/\2|\s*\/)>/g;
  for (const match of contents.matchAll(entryPattern)) {
    const [, rawKey, type, rawValue = ''] = match;
    const key = decodeXmlText(rawKey);
    if (type === 'true' || type === 'false') {
      values[key] = type === 'true';
    } else if (type === 'integer' || type === 'real') {
      values[key] = Number(rawValue);
    } else {
      values[key] = decodeXmlText(rawValue);
    }
  }
  return values;
};
const objectKeyPaths = (value, prefix = '') => {
  if (Array.isArray(value)) {
    return value.flatMap(item => objectKeyPaths(item, `${prefix}[]`));
  }
  if (!value || typeof value !== 'object') return [];

  return Object.entries(value).flatMap(([key, nested]) => {
    const keyPath = prefix ? `${prefix}.${key}` : key;
    return [keyPath, ...objectKeyPaths(nested, keyPath)];
  });
};

const packageJson = json('package.json');
const packageLock = json('package-lock.json');
const app = json('app.json').expo;
const eas = json('eas.json');
const gitignore = read('.gitignore');
const androidFirebase = json('android/app/google-services.json');
const iosFirebaseSourceContents = read('ios/GoogleService-Info.plist');
const iosFirebaseNativeContents = read('ios/Rokn/GoogleService-Info.plist');
const iosFirebase = parseFlatPlist(iosFirebaseSourceContents);
const androidGradle = read('android/app/build.gradle');
const androidManifest = read('android/app/src/main/AndroidManifest.xml');
const notificationManifestPlugin = read(
  'plugins/withNotificationManifestOverrides.js',
);
const androidDataExtractionRules = read(
  'android/app/src/main/res/xml/data_extraction_rules.xml',
);
const releaseNetworkConfig = read(
  'android/app/src/main/res/xml/network_security_config.xml',
);
const debugNetworkConfig = read(
  'android/app/src/debug/res/xml/network_security_config.xml',
);
const iosProject = read('ios/Rokn.xcodeproj/project.pbxproj');
const iosAppDelegate = read('ios/Rokn/AppDelegate.swift');
const iosEntitlements = read('ios/Rokn/Rokn.entitlements');
const iosInfoPlist = read('ios/Rokn/Info.plist');
const iosPodfile = read('ios/Podfile');
const runtimeConfig = read('src/config/runtime.ts');
const apiConfig = read('src/constants/api.ts');
const environmentExample = read('.env.example');
const androidReleaseScript = read('scripts/build-android-release.ps1');
const nativePushTokens = read('src/services/nativePushTokens.ts');
const mobileCi = read('../.github/workflows/mobile-ci.yml');
const productionApiBase =
  'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/';
const firebaseClientPaths = [
  'android/app/google-services.json',
  'ios/GoogleService-Info.plist',
  'ios/Rokn/GoogleService-Info.plist',
];
const allowedAndroidFirebasePaths = new Set([
  'project_info',
  'project_info.project_number',
  'project_info.firebase_url',
  'project_info.project_id',
  'project_info.storage_bucket',
  'client',
  'client[].client_info',
  'client[].client_info.mobilesdk_app_id',
  'client[].client_info.android_client_info',
  'client[].client_info.android_client_info.package_name',
  'client[].oauth_client',
  'client[].oauth_client[].client_id',
  'client[].oauth_client[].client_type',
  'client[].oauth_client[].android_info',
  'client[].oauth_client[].android_info.package_name',
  'client[].oauth_client[].android_info.certificate_hash',
  'client[].api_key',
  'client[].api_key[].current_key',
  'client[].services',
  'client[].services.appinvite_service',
  'client[].services.appinvite_service.other_platform_oauth_client',
  'client[].services.appinvite_service.other_platform_oauth_client[].client_id',
  'client[].services.appinvite_service.other_platform_oauth_client[].client_type',
  'client[].services.appinvite_service.other_platform_oauth_client[].ios_info',
  'client[].services.appinvite_service.other_platform_oauth_client[].ios_info.bundle_id',
  'client[].services.analytics_service',
  'client[].services.analytics_service.status',
  'client[].services.ads_service',
  'client[].services.ads_service.status',
  'configuration_version',
]);
const allowedIosFirebaseKeys = new Set([
  'API_KEY',
  'GCM_SENDER_ID',
  'PLIST_VERSION',
  'BUNDLE_ID',
  'PROJECT_ID',
  'STORAGE_BUCKET',
  'IS_ADS_ENABLED',
  'IS_ANALYTICS_ENABLED',
  'IS_APPINVITE_ENABLED',
  'IS_GCM_ENABLED',
  'IS_SIGNIN_ENABLED',
  'GOOGLE_APP_ID',
  'DATABASE_URL',
  'CLIENT_ID',
  'REVERSED_CLIENT_ID',
  'ANDROID_CLIENT_ID',
  'MEASUREMENT_ID',
]);
const androidFirebaseClient = androidFirebase.client?.find(
  client =>
    client.client_info?.android_client_info?.package_name ===
    app.android?.package,
);
const iosFirebaseKeys = Object.keys(iosFirebase);
const sensitiveFirebaseMaterial =
  /-----BEGIN [^-]*(?:PRIVATE KEY|CERTIFICATE)-----|"(?:private[_-]?key|client[_-]?secret|password|service[_-]?account|access[_-]?token|refresh[_-]?token|credential)"\s*:|<key>(?:PRIVATE[_-]?KEY|CLIENT[_-]?SECRET|PASSWORD|SERVICE[_-]?ACCOUNT|ACCESS[_-]?TOKEN|REFRESH[_-]?TOKEN|CREDENTIAL)<\/key>/i;

assert(
  app.android?.googleServicesFile === './android/app/google-services.json' &&
    app.ios?.googleServicesFile === './ios/GoogleService-Info.plist',
  'Expo does not point at the intentional checked-in Firebase client configs.',
);
assert(
  objectKeyPaths(androidFirebase).every(keyPath =>
    allowedAndroidFirebasePaths.has(keyPath),
  ),
  'Android Firebase config contains a field outside the audited public client schema.',
);
assert(
  Boolean(
    androidFirebase.project_info?.project_number &&
      androidFirebase.project_info?.project_id &&
      androidFirebase.project_info?.storage_bucket &&
      androidFirebaseClient?.client_info?.mobilesdk_app_id &&
      androidFirebaseClient?.api_key?.[0]?.current_key,
  ),
  'Android Firebase config is incomplete or is not registered for the app package.',
);
assert(
  iosFirebaseKeys.length ===
    [...iosFirebaseSourceContents.matchAll(/<key>[^<]+<\/key>/g)].length &&
    iosFirebaseKeys.every(key => allowedIosFirebaseKeys.has(key)),
  'iOS Firebase config contains a field outside the audited public client schema.',
);
assert(
  Boolean(
    iosFirebase.API_KEY &&
      iosFirebase.GCM_SENDER_ID &&
      iosFirebase.GOOGLE_APP_ID &&
      iosFirebase.PROJECT_ID &&
      iosFirebase.STORAGE_BUCKET &&
      iosFirebase.BUNDLE_ID === app.ios?.bundleIdentifier,
  ),
  'iOS Firebase config is incomplete or is not registered for the app bundle.',
);
assert(
  iosFirebaseSourceContents === iosFirebaseNativeContents,
  'The Expo-source and native-target iOS Firebase configs have drifted.',
);
assert(
  androidFirebase.project_info?.project_id === iosFirebase.PROJECT_ID &&
    androidFirebase.project_info?.storage_bucket === iosFirebase.STORAGE_BUCKET,
  'Android and iOS Firebase configs target different projects.',
);
assert(
  ![
    JSON.stringify(androidFirebase),
    iosFirebaseSourceContents,
    iosFirebaseNativeContents,
  ].some(contents => sensitiveFirebaseMaterial.test(contents)),
  'A Firebase client config contains credential-bearing material.',
);
const iosFirebaseFileReference = iosProject.match(
  /([A-F0-9]{24}) \/\* GoogleService-Info\.plist \*\/ = \{isa = PBXFileReference;[^}]*path = "?Rokn\/GoogleService-Info\.plist"?;/,
);
const iosFirebaseBuildFile = iosFirebaseFileReference
  ? iosProject.match(
      new RegExp(
        `([A-F0-9]{24}) \\/\\* GoogleService-Info\\.plist in Resources \\*\\/ = \\{isa = PBXBuildFile; fileRef = ${iosFirebaseFileReference[1]} \\/\\* GoogleService-Info\\.plist \\*\\/; \\};`,
      ),
    )
  : null;
const iosResourcesBuildPhases =
  iosProject.match(
    /\/\* Begin PBXResourcesBuildPhase section \*\/([\s\S]*?)\/\* End PBXResourcesBuildPhase section \*\//,
  )?.[1] || '';
const pbxShellQuote = String.raw`\"`;
const overEscapedPbxShellQuote = String.raw`\\\"`;
assert(
  iosFirebaseBuildFile &&
    iosResourcesBuildPhases.includes(
      `${iosFirebaseBuildFile[1]} /* GoogleService-Info.plist in Resources */`,
    ),
  'The iOS Firebase config is not bundled in the Rokn application target.',
);
const firebaseAppVersion = packageJson.dependencies?.['@react-native-firebase/app'];
const firebaseMessagingVersion =
  packageJson.dependencies?.['@react-native-firebase/messaging'];
assert(
  firebaseAppVersion === firebaseMessagingVersion &&
    packageLock.packages?.['node_modules/@react-native-firebase/app']?.version ===
      firebaseAppVersion &&
    packageLock.packages?.['node_modules/@react-native-firebase/messaging']?.version ===
      firebaseMessagingVersion,
  'React Native Firebase app and messaging must share one exact locked version.',
);
assert(
  [...iosAppDelegate.matchAll(/\bimport Firebase\b/g)].length === 1 &&
    [...iosAppDelegate.matchAll(/\bFirebaseApp\.configure\(\)/g)].length === 1 &&
    !/(?:import\s+FBSDKCoreKit|ApplicationDelegate\.shared)/.test(
      iosAppDelegate,
    ),
  'The iOS AppDelegate must initialize the locked Firebase SDK exactly once.',
);
assert(
  /override\s+func\s+application\(\s*_\s+application:\s*UIApplication,\s*didFinishLaunchingWithOptions/.test(
    iosAppDelegate,
  ),
  'The iOS AppDelegate launch callback must explicitly override ExpoAppDelegate.',
);
assert(
  [...iosAppDelegate.matchAll(/\bopen url:\s*URL\b/g)].length === 1,
  'The iOS AppDelegate must expose exactly one application URL callback.',
);
assert(
  iosProject.includes(
    `-e ${pbxShellQuote}require('expo/scripts/resolveAppEntry')${pbxShellQuote}`,
  ) &&
    iosProject.includes(
      `--print ${pbxShellQuote}require.resolve('@expo/cli', { paths: [require.resolve('expo/package.json')] })${pbxShellQuote}`,
    ),
  'The iOS bundle phase does not contain the executable Expo entry and CLI resolvers.',
);
assert(
  iosProject.includes(
    `/bin/bash ${pbxShellQuote}$REACT_NATIVE_PATH/scripts/react-native-xcode.sh${pbxShellQuote}`,
  ) &&
    !iosProject.includes(
      `${pbxShellQuote}$NODE_BINARY${pbxShellQuote} ${pbxShellQuote}$REACT_NATIVE_PATH/scripts/react-native-xcode.sh${pbxShellQuote}`,
    ),
  'The iOS bundle phase must execute react-native-xcode.sh with Bash, not Node.',
);
assert(
  !iosProject.includes(
    `${overEscapedPbxShellQuote}require('expo/scripts/resolveAppEntry')${overEscapedPbxShellQuote}`,
  ) &&
    !iosProject.includes(
      `${overEscapedPbxShellQuote}require.resolve('@expo/cli', { paths: [require.resolve('expo/package.json')] })${overEscapedPbxShellQuote}`,
    ),
  'The iOS bundle phase contains over-escaped Node expressions that /bin/sh cannot execute.',
);
const firebaseIgnoreRules = gitignore
  .split(/\r?\n/)
  .map(line => line.trim())
  .filter(line => line && !line.startsWith('#'));
const androidFirebaseIgnoreIndex = firebaseIgnoreRules.indexOf(
  '**/google-services.json',
);
const iosFirebaseIgnoreIndex = firebaseIgnoreRules.indexOf(
  '**/GoogleService-Info.plist',
);
assert(
  androidFirebaseIgnoreIndex >= 0 &&
    firebaseIgnoreRules.indexOf('!android/app/google-services.json') >
      androidFirebaseIgnoreIndex &&
    iosFirebaseIgnoreIndex >= 0 &&
    firebaseIgnoreRules.indexOf('!ios/GoogleService-Info.plist') >
      iosFirebaseIgnoreIndex &&
    firebaseIgnoreRules.indexOf('!ios/Rokn/GoogleService-Info.plist') >
      iosFirebaseIgnoreIndex,
  'Git ignore policy does not encode the audited Firebase client-config exceptions.',
);

assert(
  packageJson.packageManager === 'npm@10.9.3' &&
    fs.existsSync(path.join(root, 'package-lock.json')) &&
    !fs.existsSync(path.join(root, 'yarn.lock')) &&
    !fs.existsSync(path.join(root, 'pnpm-lock.yaml')),
  'Release builds must use the single pinned npm lockfile.',
);

const untrustedPackageSources = Object.values(
  packageLock.packages || {},
).filter(
  entry =>
    typeof entry?.resolved === 'string' &&
    !entry.resolved.startsWith('https://registry.npmjs.org/'),
);
assert(
  untrustedPackageSources.length === 0,
  'package-lock.json must resolve every remote package from the official npm registry.',
);

assert(
  packageJson.version === app.version,
  'package.json and app.json versions differ.',
);
assert(
  packageLock.packages?.['']?.version === packageJson.version,
  'package-lock.json root version differs from package.json.',
);
assert(
  Number.isInteger(app.android?.versionCode) && app.android.versionCode > 0,
  'Android versionCode must be a positive integer.',
);
assert(
  /^\d+$/.test(app.ios?.buildNumber || ''),
  'iOS buildNumber must be a positive numeric string.',
);
assert(
  app.android?.package === 'com.rokn',
  'Unexpected Android application id.',
);
assert(
  app.ios?.bundleIdentifier === 'com.rokn',
  'Unexpected iOS bundle identifier.',
);
assert(
  /applicationId\s*(?:=\s*)?["']com\.rokn["']/.test(androidGradle),
  'Native Android application id is not com.rokn.',
);
assert(
  iosProject.includes('PRODUCT_BUNDLE_IDENTIFIER = com.rokn;'),
  'Native iOS bundle identifier is not com.rokn.',
);
assert(
  iosProject.includes(`MARKETING_VERSION = ${app.version};`),
  'Native iOS marketing version differs from app.json.',
);
assert(
  iosProject.includes(`CURRENT_PROJECT_VERSION = ${app.ios.buildNumber};`),
  'Native iOS build number differs from app.json.',
);
assert(
  iosProject.includes('TARGETED_DEVICE_FAMILY = "1,2";'),
  'iPad support is declared in Expo but missing from the native target.',
);
assert(
  app.orientation === 'default',
  'Responsive phone/tablet builds must not declare a global portrait-only lock.',
);
assert(
  /<key>UISupportedInterfaceOrientations~ipad<\/key>[\s\S]*UIInterfaceOrientationLandscapeLeft[\s\S]*UIInterfaceOrientationLandscapeRight/.test(
    iosInfoPlist,
  ),
  'The iPad target must support landscape resizing as well as portrait.',
);
assert(
  iosEntitlements.includes('<key>aps-environment</key>'),
  'iOS remote-notification entitlement is missing.',
);
assert(
  Array.isArray(app.ios?.infoPlist?.UIBackgroundModes) &&
    ['fetch', 'remote-notification'].every(mode =>
      app.ios.infoPlist.UIBackgroundModes.includes(mode),
    ) &&
    /<key>UIBackgroundModes<\/key>[\s\S]*?<string>fetch<\/string>[\s\S]*?<string>remote-notification<\/string>/.test(
      iosInfoPlist,
    ),
  'iOS background push modes are missing from Expo or the native target.',
);
assert(
  packageJson.dependencies?.['@react-native-firebase/app'] &&
    packageJson.dependencies?.['@react-native-firebase/messaging'] &&
    app.plugins?.includes('@react-native-firebase/app') &&
    app.plugins?.includes('@react-native-firebase/messaging'),
  'Expo configuration does not preserve the native Firebase messaging integration.',
);
assert(
  app.plugins?.includes('./plugins/withNotificationManifestOverrides') &&
    /default_notification_color[\s\S]*tools:replace="android:resource"/.test(
      androidManifest,
    ) &&
    /default_notification_channel_id[\s\S]*tools:replace="android:value"/.test(
      androidManifest,
    ) &&
    notificationManifestPlugin.includes("'tools:replace'"),
  'Android notification metadata overrides are not preserved across Expo prebuilds.',
);
assert(
  /Platform\.OS === 'ios'[\s\S]*registerDeviceForRemoteMessages\(messaging\)[\s\S]*getToken\(messaging\)/.test(
    nativePushTokens,
  ) &&
    /Platform\.OS === 'ios'[\s\S]*onTokenRefresh\(getMessaging\(\)/.test(
      nativePushTokens,
    ),
  'iOS push registration and token rotation must use the backend-compatible Firebase token.',
);
assert(
  /linkage\s*=\s*ENV\['USE_FRAMEWORKS'\]\s*\|\|\s*'dynamic'/.test(
    iosPodfile,
  ) && /use_frameworks!\s*:linkage\s*=>\s*linkage\.to_sym/.test(iosPodfile),
  'The iOS Podfile does not use the Firebase-supported dynamic framework linkage.',
);
assert(
  !/^\s*pod\s+['"](?:FirebaseCoreInternal|GoogleUtilities|RecaptchaInterop)['"]/m.test(
    iosPodfile,
  ),
  'Firebase SPM transitive products must not also be declared as CocoaPods dependencies.',
);
assert(
  /def link_react_native_video_core_modules\(installer\)/.test(iosPodfile) &&
    /target\.name == ['"]react-native-video['"]/.test(iosPodfile) &&
    /target\.name == ['"]React-CoreModules['"]/.test(iosPodfile) &&
    /video_target\.add_dependency\(core_modules_target\)/.test(iosPodfile) &&
    /linker_flags\.push\(['"]-framework['"], framework_name\)/.test(
      iosPodfile,
    ) &&
    /link_react_native_video_core_modules\(installer\)/.test(iosPodfile),
  'The iOS Podfile does not link react-native-video to the React Native 0.83 CoreModules implementation.',
);
assert(
  iosEntitlements.includes('com.apple.developer.applesignin'),
  'Sign in with Apple entitlement is missing.',
);
assert(
  androidManifest.includes('android:allowBackup="false"'),
  'Android application backups must be disabled.',
);
assert(
  androidManifest.includes(
    'android:dataExtractionRules="@xml/data_extraction_rules"',
  ) &&
    [
      'root',
      'file',
      'database',
      'sharedpref',
      'external',
      'device_root',
      'device_file',
      'device_database',
      'device_sharedpref',
    ].every(
      domain =>
        (
          androidDataExtractionRules.match(
            new RegExp(`domain="${domain}"`, 'g'),
          ) || []
        ).length === 2,
    ),
  'Android cloud backup or device transfer is not fully excluded.',
);
assert(
  !releaseNetworkConfig.includes('cleartextTrafficPermitted="true"'),
  'Release network security permits cleartext traffic.',
);
assert(
  !releaseNetworkConfig.includes('10.0.2.2'),
  'The emulator HTTP exception leaked into the release source set.',
);
assert(
  debugNetworkConfig.includes('cleartextTrafficPermitted="true"'),
  'The Android debug client cannot reach a local HTTP Metro/API server.',
);
assert(
  runtimeConfig.includes("buildProfile === 'test'") &&
    !runtimeConfig.includes("buildProfile !== 'production'"),
  'The local demo is not restricted to an explicit test build profile.',
);
const mutableActions = [...mobileCi.matchAll(/uses:\s+[^\s@]+@([^\s#]+)/g)]
  .map(match => match[1])
  .filter(reference => !/^[a-f0-9]{40}$/.test(reference));
assert(
  mutableActions.length === 0,
  `Mobile CI uses mutable action references: ${mutableActions.join(', ')}`,
);
assert(
  mobileCi.includes('Maestro/releases/download/cli-1.39.0/maestro.zip') &&
    mobileCi.includes(
      '9ef9f19378b2928da981a8e640ef05ecdf44a4fb5ede0da2e72f96cacb75e265',
    ),
  'The protected smoke job does not verify the pinned Maestro release.',
);
assert(
  ['metro', 'metro-config', 'metro-transform-worker'].every(
    name => packageLock.packages?.[`node_modules/${name}`]?.version === '0.83.8',
  ) && !packageLock.packages?.['node_modules/image-size'],
  'Metro must remain on the image-parser-safe 0.83.8 patch without image-size.',
);

assert(
  eas.cli?.appVersionSource === 'local',
  'EAS must use the checked-in local app version.',
);
assert(
  eas.cli?.requireCommit === true &&
    packageJson.scripts?.['eas-build-post-install'] ===
      'npm run verify:release' &&
    packageJson.scripts?.['eas-build-on-success'] ===
      'node scripts/eas-build-on-success.js' &&
    eas.build?.['production-play']?.buildArtifactPaths?.includes(
      'artifacts/eas/**/*',
    ),
  'EAS production builds must require a clean commit, run the release gate, and archive provenance/symbols.',
);
for (const profileName of ['production-play', 'production-ios']) {
  const profile = eas.build?.[profileName];
  const apiUrl = profile?.env?.EXPO_PUBLIC_API_URL || '';
  assert(/^https:\/\//.test(apiUrl), `${profileName} API URL must use HTTPS.`);
  assert(
    !/(example\.com|localhost|127\.0\.0\.1)/i.test(apiUrl),
    `${profileName} contains a placeholder/local API URL.`,
  );
  assert(
    apiUrl === productionApiBase,
    `${profileName} does not use the deployed API base ${productionApiBase}.`,
  );
  assert(
    profile?.env?.EXPO_PUBLIC_BUILD_PROFILE === 'production',
    `${profileName} is not marked as a production build.`,
  );
  assert(
    profile?.env?.EXPO_PUBLIC_REQUIRE_FEATURE_FLAGS === '1',
    `${profileName} does not fail closed when remote feature flags are unavailable.`,
  );
}
const previewProfile = eas.build?.['preview-direct'];
assert(
  previewProfile?.env?.EXPO_PUBLIC_API_URL === productionApiBase &&
    previewProfile?.env?.EXPO_PUBLIC_BUILD_PROFILE === 'test' &&
    previewProfile?.env?.EXPO_PUBLIC_REQUIRE_FEATURE_FLAGS === '1' &&
    previewProfile?.env?.EXPO_PUBLIC_ENABLE_LOCAL_DEMO === '0',
  'The distributed preview must exercise the live API and remote feature flags without synthetic content.',
);
assert(
  androidReleaseScript.includes("$env:EXPO_PUBLIC_ENABLE_LOCAL_DEMO = '0'") &&
    androidReleaseScript.includes("$env:EXPO_PUBLIC_REQUIRE_FEATURE_FLAGS = '1'"),
  'The local APK builder must preserve the same live-test contract as EAS preview.',
);
assert(
  apiConfig.includes(`const defaultRoknApiUrl = '${productionApiBase}'`) &&
    environmentExample.includes(`EXPO_PUBLIC_API_URL=${productionApiBase}`) &&
    androidReleaseScript.includes(
      `Production builds must use the deployed API base '${productionApiBase}'.`,
    ),
  'The runtime, environment example, and native release script disagree on the production API base.',
);
assert(
  eas.build?.['production-play']?.env?.EXPO_PUBLIC_DISTRIBUTION_CHANNEL ===
    'play',
  'Play profile has the wrong distribution channel.',
);
assert(
  eas.build?.['production-play']?.env
    ?.ORG_GRADLE_PROJECT_roknDistributionChannel === 'play' &&
    eas.build?.['production-play']?.env?.ORG_GRADLE_PROJECT_roknBuildProfile ===
      'production' &&
    eas.build?.['production-play']?.env?.ORG_GRADLE_PROJECT_roknEnableMinify ===
      'true' &&
    eas.build?.['production-play']?.env
      ?.ORG_GRADLE_PROJECT_roknEnableResourceShrink === 'true',
  'Play EAS profile does not pass its store-safe channel/profile/R8 contract into Gradle.',
);
assert(
  eas.build?.['production-ios']?.env?.EXPO_PUBLIC_DISTRIBUTION_CHANNEL ===
    'appstore',
  'App Store profile has the wrong distribution channel.',
);
assert(
  /releaseTaskRequested[\s\S]*?\? "play" : "direct"/.test(androidGradle) &&
    /releaseTaskRequested[\s\S]*?\? "production" : "test"/.test(androidGradle),
  'Bare Android release tasks do not default to the Play-safe native contract.',
);
assert(
  /def easBuild = System\.getenv\("EAS_BUILD"\) == "true"/.test(
    androidGradle,
  ) &&
    /releaseSigningReady \|\| easBuild[\s\S]*?signingConfigs\.release/.test(
      androidGradle,
    ) &&
    /!releaseSigningReady && !easBuild/.test(androidGradle),
  'EAS signing injection is blocked or can fall back to the debug key.',
);
assert(
  /checkoutActivityEnabled:\s*roknDistributionChannel\s*!=\s*"play"\s*\?\s*"true"\s*:\s*"false"/.test(
    androidGradle,
  ) && /android:enabled="\$\{checkoutActivityEnabled\}"/.test(androidManifest),
  'The native checkout Activity is not fail-closed for Google Play builds.',
);

const requiredAssets = [
  app.icon,
  app.splash?.image,
  app.android?.adaptiveIcon?.foregroundImage,
  'android/gradle/wrapper/gradle-wrapper.jar',
  'android/app/src/main/res/mipmap-anydpi-v26/ic_launcher.xml',
  'android/app/src/main/res/mipmap-anydpi-v26/ic_launcher_round.xml',
  'android/app/src/main/res/mipmap-anydpi/ic_launcher.xml',
  'android/app/src/main/res/mipmap-anydpi/ic_launcher_round.xml',
];
for (const asset of requiredAssets) {
  const relative = String(asset || '').replace(/^\.\//, '');
  const absolute = path.join(root, relative);
  assert(
    relative && fs.existsSync(absolute) && fs.statSync(absolute).size > 0,
    `Required release asset is missing or empty: ${relative || '<unset>'}`,
  );
}

const icon = fs.readFileSync(path.join(root, app.icon.replace(/^\.\//, '')));
const isPng = icon.length >= 24 && icon.toString('ascii', 1, 4) === 'PNG';
assert(isPng, 'The app icon is not a valid PNG file.');
if (isPng) {
  assert(
    icon.readUInt32BE(16) === 1024 && icon.readUInt32BE(20) === 1024,
    'The store app icon must be exactly 1024x1024.',
  );
}

const wrapperProperties = read(
  'android/gradle/wrapper/gradle-wrapper.properties',
);
assert(
  /gradle-9\.0\.0-bin\.zip/.test(wrapperProperties),
  'The Gradle wrapper is not pinned to the React Native 0.83-compatible 9.0.0 toolchain.',
);
assert(
  /^distributionSha256Sum=8fad3d78296ca518113f3d29016617c7f9367dc005f932bd9d93bf45ba46072b$/m.test(
    wrapperProperties,
  ),
  'The Gradle 9.0.0 distribution checksum does not match the official binary.',
);
assert(
  /^validateDistributionUrl=true$/m.test(wrapperProperties),
  'Gradle wrapper URL validation is disabled.',
);

let tracked = [];
try {
  tracked = execFileSync('git', ['-C', root, 'ls-files'], {encoding: 'utf8'})
    .split(/\r?\n/)
    .filter(Boolean);
} catch {
  failures.push('Could not inspect tracked files for credentials.');
}
const trackedFirebaseClientConfigs = tracked.filter(file =>
  /(?:^|\/)(?:google-services\.json|GoogleService-Info\.plist)$/.test(file),
);
assert(
  firebaseClientPaths.every(file =>
    trackedFirebaseClientConfigs.includes(file),
  ) &&
    trackedFirebaseClientConfigs.every(file =>
      firebaseClientPaths.includes(file),
    ),
  'Only the three audited Firebase mobile client configs may be tracked.',
);
const forbiddenTracked = tracked.filter(
  file =>
    file !== 'android/app/debug.keystore' &&
    /\.(jks|keystore|p12|mobileprovision|ipa|apk|aab)$/i.test(file),
);
assert(
  forbiddenTracked.length === 0,
  `Signing credential or release artifact is tracked: ${forbiddenTracked.join(
    ', ',
  )}`,
);

if (failures.length) {
  console.error(`Release configuration failed (${failures.length}):`);
  failures.forEach(item => console.error(`- ${item}`));
  process.exit(1);
}

console.log('Release configuration contract is consistent.');
