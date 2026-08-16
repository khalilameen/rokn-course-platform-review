# iOS native notice generation blocked

This is a build guard, not a distributable third-party notice artifact.

The checked-in `Podfile.lock` pins React 0.83.1 while the npm production closure
uses React Native 0.83.10 and omits current Expo Pods. Release builds reject this
file. On macOS, run `pod install`, verify the lock, and then run
`npm run notices:native:generate` to replace it with the checksum-bound notices.
