'use strict';

const DISABLED_IMAGE_TYPES = Object.freeze([
  'heif',
  'icns',
  'jxl',
  'jxl-stream',
]);
const BLOCKED_METRO_ASSET_EXTENSIONS = Object.freeze([
  'heic',
  'heif',
  'icns',
  'jxl',
]);

const applyMetroImageParserPolicy = () => {
  const imageSize = require('image-size');
  if (typeof imageSize.disableTypes !== 'function') {
    throw new Error(
      'The installed image-size package cannot enforce the Metro parser policy.',
    );
  }
  imageSize.disableTypes([...DISABLED_IMAGE_TYPES]);
};

module.exports = {
  applyMetroImageParserPolicy,
  BLOCKED_METRO_ASSET_EXTENSIONS,
  DISABLED_IMAGE_TYPES,
};
