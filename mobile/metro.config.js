const {getDefaultConfig} = require('expo/metro-config');
const {
  applyMetroImageParserPolicy,
  BLOCKED_METRO_ASSET_EXTENSIONS,
} = require('./scripts/metro-image-parser-policy');

// Temporary containment for Metro's image-size@1.2.1 dependency: disable the
// ICNS/JXL/HEIF sniffers before Metro imports its asset pipeline. The release
// audit expires this exception on 2026-09-15 so it cannot become permanent.
applyMetroImageParserPolicy();

/**
 * Metro configuration
 * https://reactnative.dev/docs/metro
 *
 * @type {import('@react-native/metro-config').MetroConfig}
 */
const config = getDefaultConfig(__dirname);
const blockedAssetExtensions = new Set(BLOCKED_METRO_ASSET_EXTENSIONS);
config.resolver.assetExts = config.resolver.assetExts.filter(
  extension => !blockedAssetExtensions.has(extension.toLowerCase()),
);

module.exports = config;
