'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const {
  applyNotificationManifestOverrides,
} = require('../../plugins/withNotificationManifestOverrides');

const manifest = metaData => ({
  manifest: {
    $: {'xmlns:android': 'http://schemas.android.com/apk/res/android'},
    application: [{$: {'android:name': '.MainApplication'}, 'meta-data': metaData}],
  },
});

test('keeps Rokn notification metadata authoritative after Expo prebuild', () => {
  const input = manifest([
    {
      $: {
        'android:name':
          'com.google.firebase.messaging.default_notification_color',
        'android:resource': '@color/white',
      },
    },
  ]);

  const output = applyNotificationManifestOverrides(input);
  const entries = output.manifest.application[0]['meta-data'];
  const byName = name =>
    entries.find(item => item.$['android:name'] === name).$;

  assert.equal(
    output.manifest.$['xmlns:tools'],
    'http://schemas.android.com/tools',
  );
  assert.deepEqual(
    byName('com.google.firebase.messaging.default_notification_color'),
    {
      'android:name':
        'com.google.firebase.messaging.default_notification_color',
      'android:resource': '@color/notification_icon_color',
      'tools:replace': 'android:resource',
    },
  );
  assert.deepEqual(
    byName('com.google.firebase.messaging.default_notification_channel_id'),
    {
      'android:name':
        'com.google.firebase.messaging.default_notification_channel_id',
      'android:value': 'rokn-updates',
      'tools:replace': 'android:value',
    },
  );
});
