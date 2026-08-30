import * as access from '../src/services/api/access';
import * as certificates from '../src/services/api/certificates';
import * as courses from '../src/services/api/courses';
import * as economy from '../src/services/api/economy';
import * as engagement from '../src/services/api/engagement';
import * as learning from '../src/services/api/learning';
import * as notifications from '../src/services/api/notifications';
import * as profile from '../src/services/api/profile';
import * as roknApi from '../src/services/roknApi';

describe('roknApi compatibility facade', () => {
  it('re-exports every domain function without wrapping it', () => {
    const domainExports = Object.assign(
      {},
      access,
      certificates,
      courses,
      economy,
      engagement,
      learning,
      notifications,
      profile,
    );

    expect(Object.keys(roknApi).sort()).toEqual(
      Object.keys(domainExports).sort(),
    );
    Object.entries(domainExports).forEach(([name, implementation]) => {
      expect(roknApi[name as keyof typeof roknApi]).toBe(implementation);
    });
  });

  it.each([
    ['purchaseProductionCourse', 'purchaseCourse'],
    ['redeemProductionCourseCode', 'redeemCourseCode'],
    ['getProductionCourseChatUpgradeQuote', 'getCourseChatUpgradeQuote'],
    ['purchaseProductionCourseChatUpgrade', 'purchaseCourseChatUpgrade'],
    ['getProductionFullTrackUpgradeQuote', 'getFullTrackUpgradeQuote'],
    ['purchaseProductionFullTrackUpgrade', 'purchaseFullTrackUpgrade'],
    ['getProductionCertificates', 'getCertificates'],
    ['issueProductionCertificate', 'issueCertificate'],
    ['getProductionLearningCourses', 'getLearningCourses'],
    ['getProductionCourseDetails', 'getCourseDetails'],
    ['hasProductionSession', 'hasSession'],
    ['getProductionOwnedCourseIds', 'getOwnedCourseIds'],
    ['claimProductionDailyReward', 'claimDailyReward'],
    ['getProductionWallet', 'getWallet'],
    ['getProductionCoinPackages', 'getCoinPackages'],
    ['getProductionCoinTasks', 'getCoinTasks'],
    ['startProductionCoinTask', 'startCoinTask'],
    ['claimProductionCoinTask', 'claimCoinTask'],
    ['getCachedProductionLearningDashboard', 'getCachedLearningDashboard'],
    ['getProductionLearningDashboard', 'getLearningDashboard'],
    ['getProductionSavedLessonsPage', 'getSavedLessonsPage'],
    ['getProductionSavedLessons', 'getSavedLessons'],
    ['deleteProductionSavedLesson', 'deleteSavedLesson'],
    ['getProductionNotificationsPage', 'getNotificationsPage'],
    ['getProductionNotifications', 'getNotifications'],
    ['markProductionNotificationRead', 'markNotificationRead'],
    ['markAllProductionNotificationsRead', 'markAllNotificationsRead'],
    ['getProductionProfile', 'getProfile'],
    ['getProductionPortfolioProfile', 'getPortfolioProfile'],
    ['updateProductionProfile', 'updateProfile'],
    ['updateProductionNotificationStatus', 'updateNotificationStatus'],
    ['updateProductionPrivacyPreferences', 'updatePrivacyPreferences'],
    ['updateProductionPlaybackPreferences', 'updatePlaybackPreferences'],
    ['clearProductionWatchHistory', 'clearWatchHistory'],
    ['getProductionWatchHistory', 'getWatchHistory'],
    ['updateProductionPortfolioProfile', 'updatePortfolioProfile'],
    ['updateProductionPortfolioVisibility', 'updatePortfolioVisibility'],
    ['getProductionPortfolio', 'getPortfolio'],
    ['createProductionPortfolioItem', 'createPortfolioItem'],
    ['getProductionEligibleProjects', 'getEligibleProjects'],
    ['deleteProductionPortfolioItem', 'deletePortfolioItem'],
  ] as const)('%s aliases %s', (legacyName, canonicalName) => {
    expect(roknApi[legacyName]).toBe(roknApi[canonicalName]);
  });
});
