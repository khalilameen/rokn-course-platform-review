import {buildNoticeSections} from '../src/screens/Informations/thirdPartyNoticeModel';

describe('third-party notice catalogue', () => {
  it('exposes npm, Android and iOS inventories as separate sections', () => {
    const sections = buildNoticeSections(
      {
        schemaVersion: 2,
        packageCount: 1,
        packagePathCount: 1,
        inventoryHash: 'npm-hash',
        packages: [
          {
            name: 'example',
            version: '1.0.0',
            license: 'MIT',
            declaredLicense: 'MIT',
            sourceUrl: 'https://www.npmjs.com/package/example/v/1.0.0',
            legalSource: 'package-root',
            legalFileCount: 1,
            apacheNotice: null,
          },
        ],
      },
      {
        androidDependencyCount: 1,
        androidProjectComponentCount: 1,
        podDependencyCount: 1,
        android: [
          {
            coordinate: 'example:android:1.0.0',
            licenses: ['Apache-2.0'],
            legalDocumentCount: 1,
          },
        ],
        androidProjects: [
          {
            coordinate: 'gradle-project::example',
            licenses: ['MIT'],
            legalDocumentCount: 1,
          },
        ],
        pods: [
          {
            coordinate: 'ExamplePod@1.0.0',
            licenses: ['MIT'],
            legalDocumentCount: 1,
          },
        ],
        bundledAssets: [
          {
            coordinate: 'font:Cairo',
            licenses: ['OFL-1.1'],
            legalDocumentCount: 1,
          },
        ],
      },
    );

    expect(sections.map(section => section.key)).toEqual([
      'npm',
      'android',
      'ios',
      'assets',
    ]);
    expect(sections.map(section => section.data.length)).toEqual([1, 2, 1, 1]);
    expect(sections[1].data.map(item => item.coordinate)).toEqual([
      'maven:example:android:1.0.0',
      'project:gradle-project::example',
    ]);
  });

  it('makes an ungenerated iOS inventory explicit instead of silently omitting it', () => {
    const sections = buildNoticeSections(
      {
        schemaVersion: 2,
        packageCount: 0,
        packagePathCount: 0,
        inventoryHash: 'npm-hash',
        packages: [],
      },
      {
        androidDependencyCount: 0,
        androidProjectComponentCount: 0,
        podDependencyCount: null,
        android: [],
        androidProjects: [],
        pods: [],
        bundledAssets: [],
      },
    );

    expect(sections[2].data).toEqual([
      {kind: 'status', coordinate: 'ios-inventory-pending'},
    ]);
  });
});
