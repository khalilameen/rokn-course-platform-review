export type PackageNotice = {
  name: string;
  version: string;
  license: string;
  declaredLicense: string | null;
  sourceUrl: string;
  legalSource: 'package-root' | 'reviewed-metadata-fallback';
  legalFileCount: number;
  apacheNotice: 'included' | 'not-published' | null;
};

export type NativeNotice = {
  coordinate: string;
  licenses: string[];
  legalDocumentCount: number;
};

export type ThirdPartyNoticeData = {
  schemaVersion: number;
  packageCount: number;
  packagePathCount: number;
  inventoryHash: string;
  packages: PackageNotice[];
};

export type NativeThirdPartyNoticeData = {
  androidDependencyCount: number;
  androidProjectComponentCount: number;
  podDependencyCount: number | null;
  android: NativeNotice[];
  androidProjects: NativeNotice[];
  pods: NativeNotice[];
  bundledAssets: NativeNotice[];
};

export type NoticeListItem =
  | {kind: 'npm'; coordinate: string; notice: PackageNotice}
  | {
      kind: 'native';
      coordinate: string;
      notice: NativeNotice;
      origin: 'maven' | 'gradle-project' | 'cocoapods';
    }
  | {kind: 'status'; coordinate: 'ios-inventory-pending'};

export type NoticeSection = {
  key: 'npm' | 'android' | 'ios' | 'assets';
  title: string;
  countLabel: string;
  data: NoticeListItem[];
};

export const buildNoticeSections = (
  npm: ThirdPartyNoticeData,
  native: NativeThirdPartyNoticeData,
): NoticeSection[] => [
  {
    key: 'npm',
    title: 'مكتبات JavaScript (npm)',
    countLabel: `${npm.packageCount} حزمة`,
    data: npm.packages.map(notice => ({
      kind: 'npm',
      coordinate: `${notice.name}@${notice.version}`,
      notice,
    })),
  },
  {
    key: 'android',
    title: 'مكتبات Android الأصلية',
    countLabel: `${
      native.androidDependencyCount + native.androidProjectComponentCount
    } مكتبة`,
    data: [
      ...native.android.map(notice => ({
        kind: 'native' as const,
        coordinate: `maven:${notice.coordinate}`,
        notice,
        origin: 'maven' as const,
      })),
      ...native.androidProjects.map(notice => ({
        kind: 'native' as const,
        coordinate: `project:${notice.coordinate}`,
        notice,
        origin: 'gradle-project' as const,
      })),
    ],
  },
  {
    key: 'ios',
    title: 'مكتبات iOS الأصلية',
    countLabel:
      native.podDependencyCount === null
        ? 'بانتظار جرد بناء iOS'
        : `${native.podDependencyCount} مكتبة`,
    data:
      native.podDependencyCount === null
        ? [{kind: 'status', coordinate: 'ios-inventory-pending'}]
        : native.pods.map(notice => ({
            kind: 'native' as const,
            coordinate: `pod:${notice.coordinate}`,
            notice,
            origin: 'cocoapods' as const,
          })),
  },
  {
    key: 'assets',
    title: 'أصول مفتوحة المصدر مرفقة بالتطبيق',
    countLabel: `${native.bundledAssets.length} أصل`,
    data: native.bundledAssets.map(notice => ({
      kind: 'native' as const,
      coordinate: `asset:${notice.coordinate}`,
      notice,
      origin: 'gradle-project' as const,
    })),
  },
];
