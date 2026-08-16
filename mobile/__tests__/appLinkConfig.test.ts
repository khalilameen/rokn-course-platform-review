import fs from 'fs';
import path from 'path';
import renderIntentFilters from '@expo/config-plugins/build/android/IntentFilters';
import appConfig from '../app.json';

const hosts = ['rokn.app', 'www.rokn.app', 'rokn.com', 'www.rokn.com'];
const paths = ['/home', '/profile', '/wallet', '/course', '/courses'];
const expectedData = (host: string) => [
  {scheme: 'https'},
  {host},
  ...paths.map(routePath => ({path: routePath})),
  ...paths.map(pathPrefix => ({pathPrefix: `${pathPrefix}/`})),
];

const matchesConfiguredPath = (item: any, pathname: string) =>
  typeof item.path === 'string'
    ? pathname === item.path
    : typeof item.pathPrefix === 'string' && pathname.startsWith(item.pathPrefix);

describe('native app-link scope', () => {
  it('keeps Expo generation split into one attribute per data tag', () => {
    const filters = appConfig.expo.android.intentFilters;
    const rendered = renderIntentFilters(filters);
    for (const [index, host] of hosts.entries()) {
      const hostFilter = filters[index];
      expect(hostFilter?.autoVerify).toBe(true);
      expect(hostFilter?.data).toEqual(expectedData(host));
      expect(rendered[index].data?.map(item => item.$)).toEqual(
        expectedData(host).map(item =>
          Object.fromEntries(
            Object.entries(item).map(([key, value]) => [
              `android:${key}`,
              value,
            ]),
          ),
        ),
      );
    }
  });

  it('keeps the checked-in Android manifest path-scoped too', () => {
    const manifest = fs.readFileSync(
      path.resolve(__dirname, '../android/app/src/main/AndroidManifest.xml'),
      'utf8',
    );
    const verifiedFilters = [
      ...manifest.matchAll(
        /<intent-filter android:autoVerify="true">([\s\S]*?)<\/intent-filter>/g,
      ),
    ].map(match => match[1]);
    expect(verifiedFilters).toHaveLength(hosts.length);
    for (const host of hosts) {
      const hostFilter = verifiedFilters.find(filter =>
        filter.includes(`<data android:host="${host}" />`),
      );
      const dataAttributes = [
        ...(hostFilter || '').matchAll(/<data\s+([^>]+?)\s*\/>/g),
      ].map(match => match[1]);
      expect(dataAttributes).toEqual(
        expectedData(host).map(item => {
          const [key, value] = Object.entries(item)[0];
          return `android:${key}="${value}"`;
        }),
      );
    }
  });

  it.each(['/@student', '/course-evil', '/coursesX', '/homepage'])(
    'does not claim unrelated website path %s',
    pathname => {
      const items = appConfig.expo.android.intentFilters.flatMap(
        filter => filter.data,
      );
      expect(items.some(item => matchesConfiguredPath(item, pathname))).toBe(
        false,
      );
    },
  );
});
