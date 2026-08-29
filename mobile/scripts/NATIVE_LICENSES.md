# Native dependency license gate

Rokn treats the JavaScript, Android, and iOS production closures as separate
legal inputs. A release is allowed only when every closure is current and its
generated notices match the resolved artifacts.

## Android

`npm run notices:android:generate` resolves `releaseRuntimeClasspath` through
Gradle, hashes every resolved POM and AAR/JAR, extracts package-specific
`LICENSE`, `COPYING`, `NOTICE`, and `COPYRIGHT` files (including nested JARs),
and writes:

- `scripts/licenses/android-release-notices.generated.json`
- `ANDROID_THIRD_PARTY_NOTICES.md`
- `android/app/src/main/assets/NATIVE_THIRD_PARTY_NOTICES.md`
- `src/data/nativeThirdPartyNotices.generated.json` (metadata only)

`npm run licenses:android:check` resolves the closure again and fails if a
coordinate, artifact, POM, legal document, exact license choice, or reviewed
absence has changed. It does not trust Gradle cache paths and does not include
license text in the React Native JSON bundle.

The resolver is strict and non-lenient. It asserts zero unresolved dependency
results, enumerates the selected runtime configuration's unfiltered artifact
set, rejects opaque/local-file artifacts, and classifies every Gradle project
component as an exact npm production package. POM-only constraints remain in
the inventory even when they publish no runtime artifact.

## iOS

Native iOS notices may only be generated from a current `ios/Podfile.lock`.
The full generator first runs `npm run verify:ios-lock`; it then requires an
installed `ios/Pods/Manifest.lock` that exactly matches `ios/Podfile.lock`.
Each remote Pod is bound to a deterministic SHA-256 tree digest of the actual
installed `ios/Pods/<Pod>` source bytes. Local React Native/npm Pods remain
bound to their exact npm lock SRI. Legal coverage is scanned recursively from
those installed source roots for `LICENSE`, `LICENCE`, `COPYING`, `NOTICE`, and
`COPYRIGHT` files; canonical terms alone cannot hide a missing package notice.
The Release Xcode target re-verifies the installed source hashes immediately
before compilation through `--ios-sources-check`.

The checked-in Pod and Ruby locks are generated together by the root
`refresh-ios-lock.yml` workflow on maintained macOS, Ruby, Bundler, CocoaPods,
and Xcode versions; do not edit either lock by hand. To reproduce the full
installed-source gate locally on macOS:

```sh
npm ci --include=dev
bundle install
cd ios
bundle exec pod install
cd ..
npm run verify:ios-lock
npm run notices:native:generate
npm run licenses:native:check
```

CI and release automation use `bundle exec pod install --deployment`,
assert that `bundle exec pod --version` exactly equals the `COCOAPODS` value in
`Podfile.lock`, assert runtime Ruby and Bundler exactly match `Gemfile.lock`,
assert Xcode 26.2 or newer, then run the native license/source gate. A bare
`pod install` or a legal snapshot without a matching installed sandbox is a
release NO-GO.

The generator writes the iOS snapshot, platform notice artifact, bundled
resource, and compact in-app metadata. The cross-platform `verify:release`
command runs `licenses:native:portable-check`: it validates the lock, snapshot,
local podspecs, pinned npm identities, and retained legal text without claiming
to inspect an unavailable macOS Pod sandbox. The iOS CI and Xcode Release build
run the full installed-source check after `pod install --deployment`.
