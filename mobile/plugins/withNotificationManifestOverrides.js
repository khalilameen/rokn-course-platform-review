'use strict';

const {AndroidConfig, withAndroidManifest} = require('@expo/config-plugins');

const TOOLS_NAMESPACE = 'http://schemas.android.com/tools';
const overrides = [
  {
    name: 'com.google.firebase.messaging.default_notification_color',
    attribute: 'android:resource',
    value: '@color/notification_icon_color',
  },
  {
    name: 'com.google.firebase.messaging.default_notification_channel_id',
    attribute: 'android:value',
    value: 'rokn-updates',
  },
];

const applyNotificationManifestOverrides = androidManifest => {
  androidManifest.manifest.$ ||= {};
  androidManifest.manifest.$['xmlns:tools'] = TOOLS_NAMESPACE;

  const application =
    AndroidConfig.Manifest.getMainApplicationOrThrow(androidManifest);
  application['meta-data'] ||= [];

  for (const override of overrides) {
    let metaData = application['meta-data'].find(
      item => item.$?.['android:name'] === override.name,
    );
    if (!metaData) {
      metaData = {$: {'android:name': override.name}};
      application['meta-data'].push(metaData);
    }
    metaData.$[override.attribute] = override.value;
    metaData.$['tools:replace'] = override.attribute;
  }

  return androidManifest;
};

const withNotificationManifestOverrides = config =>
  withAndroidManifest(config, nextConfig => {
    applyNotificationManifestOverrides(nextConfig.modResults);
    return nextConfig;
  });

module.exports = withNotificationManifestOverrides;
module.exports.applyNotificationManifestOverrides =
  applyNotificationManifestOverrides;
