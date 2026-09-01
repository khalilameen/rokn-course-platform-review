/**
 * Client fallback for Rokn's closed-loop credit economy. The server defines
 * deployed values; these defaults keep demo and offline views aligned.
 */
export const ECONOMY_CONFIG = {
  version: 1,
  minimumCoursePrice: 4_000,
  // Mirrors the deployed first-registration promise and backend default.
  welcomeReward: 20,
  rewardBalanceCap: 1_200,
  maxRewardContributionPerCourse: 1_200,
  spendPolicy: 'reward_first_then_paid' as const,
  packages: [
    // Package sizing leaves a small balance after a typical course.
    {id: 'demo-4200', coins: 4_200, price: 249, label: 'رصيد مدفوع'},
    {id: 'demo-8500', coins: 8_500, price: 479, label: 'رصيد مدفوع'},
    {id: 'demo-13500', coins: 13_500, price: 699, label: 'رصيد مدفوع'},
  ],
  rewards: {
    coinGuide: 50,
    externalTask: 75,
    daily: {amount: 15, dailyCap: 15, rolling30DayCap: 150},
    study: {
      amount: 10,
      minimumQualifiedMinutes: 5,
      dailyCap: 20,
      rolling30DayCap: 200,
    },
    firstProject: {amount: 150, lifetimeCap: 150},
    courseCompletion: {amount: 200, rolling30DayCap: 200},
  },
} as const;

export const ECONOMY_RULES = [
  'اختر فئة الكورس التي تناسبك',
  'نستخدم عملات المكافآت أولًا ثم الرصيد المدفوع',
  'سترى الرصيد المطلوب قبل تأكيد الشراء',
] as const;

export const availableRewardRoom = (currentRewardBalance: number) =>
  Math.max(
    0,
    ECONOMY_CONFIG.rewardBalanceCap - Math.max(0, currentRewardBalance),
  );

export const cappedRewardCredit = (
  currentRewardBalance: number,
  requestedReward: number,
) =>
  Math.min(
    Math.max(0, requestedReward),
    availableRewardRoom(currentRewardBalance),
  );

export const selectSmallestSufficientPackage = <T extends {coins: number}>(
  packages: T[],
  shortfall: number,
): T | undefined => {
  const ordered = packages
    .filter(item => Number.isFinite(item.coins) && item.coins > 0)
    .slice()
    .sort((left, right) => left.coins - right.coins);

  return ordered.find(item => item.coins >= Math.max(0, shortfall));
};
