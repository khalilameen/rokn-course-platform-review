# Android release contract

Rokn has two deliberately separate distribution channels and one test profile.
The build scripts make the differences explicit so a cached JavaScript bundle,
debug key, or development API cannot leak into a store artifact.

## Commands

| Command | Output | Signing | Quality gates |
| --- | --- | --- | --- |
| `npm run apk:test` | `artifacts/Rokn-test.apk` | Debug key | Android compilation |
| `npm run apk:direct` | `artifacts/Rokn-direct.apk` | Release upload key | TypeScript, ESLint, Jest, Android lint/unit tests |
| `npm run aab:play` | `artifacts/Rokn-play.aab` | Release upload key | TypeScript, ESLint, Jest, Android lint/unit tests |

`npm run apk` is a convenience alias for the installable test APK. `npm run
apk:play` is a compatibility alias for the Play AAB; Play never receives an APK
from this pipeline.

The test APK targets `arm64-v8a` by default to keep local iteration fast. Set
`ROKN_ANDROID_ARCHITECTURES` when a test device needs another ABI. The direct
production APK includes both ARM ABIs, and the Play bundle includes all supported
ABIs for Play-managed delivery.

## Production environment

Set `EXPO_PUBLIC_API_URL` in the process environment or in the ignored
`.env.production` file. The value must be an absolute HTTPS URL. The build stops
before Gradle when it is missing or points at a local/example host.

The script injects `EXPO_PUBLIC_DISTRIBUTION_CHANNEL` and
`EXPO_PUBLIC_BUILD_PROFILE`. Do not put either value in an environment file.

## Release signing

Keep these values in `%USERPROFILE%\.gradle\gradle.properties` or inject them
from CI. Never commit the keystore or passwords.

```properties
UPLOAD_STORE_FILE=C:/secure/rokn-upload.keystore
UPLOAD_STORE_PASSWORD=...
UPLOAD_KEY_ALIAS=...
UPLOAD_KEY_PASSWORD=...
```

Production and Play configuration fails if any value is missing, incomplete,
or the keystore does not exist. A production APK is also checked after the build
and rejected if its certificate is the Android debug certificate. The Play AAB
is verified with the JDK signer as well. Production artifacts can only be built
from a clean Git tree; local investigations use `npm run apk:test`.

## Diagnostics and symbol files

Every successful build writes a sidecar JSON file containing the version,
channel, profile, commit, size, artifact SHA-256, signer SHA-256, and the exact
API base with both base and path hashes. Production builds also copy
the Hermes source map and R8 mapping into `artifacts/<artifact>-symbols/`. Archive
the artifact, its JSON file, and the symbol directory together for each release.

JDK 17 is required by the React Native Gradle plugin. The script uses the local
`.jdk17` cache when present, then `JAVA_HOME`. It never rewrites installed files
inside `node_modules`.
