import {publicRequest} from '../../constants/api';
import type {DemoCoinPackage} from '../demoExperience';
import {payload, resourceList} from './common';
import {
  DISTRIBUTION_CHANNEL,
  IS_STORE_DISTRIBUTION,
} from '../../constants/distribution';

type WalletBreakdownDto = {
  total_balance?: unknown;
  paid_balance?: unknown;
  purchased_balance?: unknown;
  reward_balance?: unknown;
  course_spendable_balance?: unknown;
  reward_contribution_cap_per_course?: unknown;
};

type WalletTransactionDto = {
  id?: unknown;
  amount?: unknown;
  direction?: unknown;
  occurred_at?: string;
  category?: string;
};

type WalletDto = {
  total_balance?: unknown;
  balance?: unknown;
  paid_balance?: unknown;
  purchased_balance?: unknown;
  reward_balance?: unknown;
  course_spendable_balance?: unknown;
  spendable_balance?: unknown;
  reward_contribution_cap_per_course?: unknown;
  spend_policy?: unknown;
  coin_rules?: unknown;
  recent_transactions?: WalletTransactionDto[];
  breakdown?: WalletBreakdownDto;
};

type CoinPackageDto = {
  id?: unknown;
  coins?: unknown;
  price?: unknown;
  direct_price?: unknown;
  name?: unknown;
  name_ar?: unknown;
  name_en?: unknown;
  recommended?: unknown;
  store_products?: {
    google?: unknown;
    apple?: unknown;
  };
};

type CoinTaskDto = {
  id?: unknown;
  task_state?: unknown;
  action_key?: unknown;
  requires_external_visit?: unknown;
  title_ar?: unknown;
  title_en?: unknown;
  coins_amount?: unknown;
  action_url?: unknown;
};

export type WalletSnapshot = {
  balance: number;
  spendableBalance: number;
  paidBalance: number;
  rewardBalance: number;
  rewardContributionCap: number;
  spendPolicy: string;
  coinRules: string[];
  transactions: Array<{
    id: string;
    amount: number;
    occurred_at?: string;
    category?: string;
  }>;
};

export type RewardResult = {
  awarded: number;
  balance: number;
  rewardBalance: number;
};

export const claimDailyReward = async (): Promise<RewardResult> => {
  const data = payload(await publicRequest.post('rewards/daily'));
  return {
    awarded: Math.max(0, Number(data.awarded || 0)),
    balance: Math.max(0, Number(data.balance || 0)),
    rewardBalance: Math.max(0, Number(data.reward_balance || 0)),
  };
};

export const getWallet = async (): Promise<WalletSnapshot> => {
  const data = payload<WalletDto>(await publicRequest.get('wallet'));
  const balance = Math.max(
    0,
    Number(
      data.total_balance ?? data.breakdown?.total_balance ?? data.balance ?? 0,
    ) || 0,
  );
  const paidBalance = Math.max(
    0,
    Number(
      data.paid_balance ??
        data.purchased_balance ??
        data.breakdown?.paid_balance ??
        data.breakdown?.purchased_balance ??
        0,
    ) || 0,
  );
  return {
    balance,
    paidBalance,
    rewardBalance: Math.max(
      0,
      Number(
        data.reward_balance ??
          data.breakdown?.reward_balance ??
          balance - paidBalance,
      ) || 0,
    ),
    spendableBalance: Math.max(
      0,
      Number(
        data.course_spendable_balance ??
          data.spendable_balance ??
          data.breakdown?.course_spendable_balance ??
          balance,
      ) || 0,
    ),
    rewardContributionCap: Math.max(
      0,
      Number(
        data.reward_contribution_cap_per_course ??
          data.breakdown?.reward_contribution_cap_per_course ??
          0,
      ) || 0,
    ),
    spendPolicy: String(data.spend_policy || 'reward_first_then_paid'),
    coinRules: Array.isArray(data.coin_rules)
      ? data.coin_rules.map(String).filter(Boolean)
      : typeof data.coin_rules === 'string'
      ? data.coin_rules
          .split(/\r?\n|[.!؟]\s+/)
          .map((rule: string) => rule.trim())
          .filter(Boolean)
      : [],
    transactions: Array.isArray(data.recent_transactions)
      ? data.recent_transactions.map(item => ({
          ...item,
          id: String(item.id),
          amount:
            String(item.direction).toLowerCase() === 'debit'
              ? -Math.abs(Number(item.amount || 0))
              : Math.abs(Number(item.amount || 0)),
        }))
      : [],
  };
};

export const getCoinPackages = async (): Promise<DemoCoinPackage[]> => {
  const data = payload<CoinPackageDto[] | {packages?: unknown}>(
    await publicRequest.get('packages'),
  );
  const items = Array.isArray(data)
    ? data
    : resourceList<CoinPackageDto>(data.packages);
  const packages = items
    .filter(item => item.id !== null && item.id !== undefined)
    .map(item => ({
      id: String(item.id),
      coins: Number(item.coins || 0),
      price: Number(
        DISTRIBUTION_CHANNEL === 'direct'
          ? item.direct_price ?? item.price ?? 0
          : item.price ?? 0,
      ),
      label: String(item.name || item.name_ar || item.name_en || 'باقة عملات'),
      recommended: Boolean(item.recommended),
      storeProductIds: {
        google: item.store_products?.google
          ? String(item.store_products.google)
          : undefined,
        apple: item.store_products?.apple
          ? String(item.store_products.apple)
          : undefined,
      },
    }));

  if (!IS_STORE_DISTRIBUTION) return packages;
  const {hydrateNativeStorePackages} = await import('../nativeStoreBilling');
  return hydrateNativeStorePackages(packages);
};

export type CoinTask = {
  id: string;
  serverId: string;
  title: string;
  description: string;
  reward: number;
  url?: string;
  status: 'available' | 'started' | 'claimed';
  actionKey: string;
  requiresExternalVisit: boolean;
};

const taskDescription = (requiresExternalVisit: boolean, actionKey: string) => {
  if (actionKey === 'link_whatsapp') {
    return 'اضغط، ابعت الرسالة الجاهزة، وارجع تلاقي العملات وصلت.';
  }
  if (actionKey.toLowerCase().includes('coin_guide')) {
    return 'راجع قواعد الرصيد مرة واحدة ثم استلم مكافأتك.';
  }
  return requiresExternalVisit
    ? 'افتح الصفحة ثم ارجع إلى ركن لاستلام المكافأة.'
    : 'أكمل المهمة مرة واحدة ثم استلم مكافأتك.';
};

const truthfulTaskTitle = (title: string, actionKey: string) => {
  const key = actionKey.toLowerCase();
  if (key.includes('coin_guide')) return 'اعرف كيف يعمل رصيد ركن';
  if (key.includes('instagram')) return 'افتح حساب ركن على Instagram';
  if (key.includes('tiktok')) return 'افتح حساب ركن على TikTok';
  if (key.includes('youtube')) return 'افتح قناة ركن على YouTube';
  if (key === 'link_whatsapp') return 'اربط واتسابك بحساب ركن';
  return title;
};

export const getCoinTasks = async (): Promise<CoinTask[]> => {
  const response = await publicRequest.get('coin-earning-methods');
  const data = payload<CoinTaskDto[] | {data?: CoinTaskDto[]}>(response);
  const items = resourceList<CoinTaskDto>(data);
  return items
    .filter(item => item.id !== null && item.id !== undefined)
    .map(item => {
      const state = String(item.task_state || 'available');
      const rawActionKey = String(item.action_key || '');
      const replacesNotificationReward = rawActionKey
        .toLowerCase()
        .includes('notification');
      // Older servers may still expose the retired permission reward. Treat the
      // same immutable server task as the in-app coin guide until the seeder has
      // renamed it, preserving claim history without requesting a permission.
      const actionKey = replacesNotificationReward
        ? 'demo_coin_guide'
        : rawActionKey;
      const requiresExternalVisit = replacesNotificationReward
        ? false
        : Boolean(item.requires_external_visit);
      const rawTitle = String(item.title_ar || item.title_en || 'مهمة مكافأة');
      return {
        id: `production-${item.id}`,
        serverId: String(item.id),
        title: truthfulTaskTitle(rawTitle, actionKey),
        description: taskDescription(requiresExternalVisit, actionKey),
        reward: Number(item.coins_amount || 0),
        url:
          !replacesNotificationReward && item.action_url
            ? String(item.action_url)
            : undefined,
        status:
          state === 'claimed'
            ? 'claimed'
            : state === 'started' || state === 'ready_to_claim'
            ? 'started'
            : 'available',
        actionKey,
        requiresExternalVisit,
      };
    });
};

export const startCoinTask = async (task: CoinTask) => {
  const data = payload(
    await publicRequest.post(`coin-earning-methods/${task.serverId}/start`),
  );
  return {
    status: String(data.task_state || 'started'),
    url: data.action_url ? String(data.action_url) : task.url,
  };
};

export const claimCoinTask = async (task: CoinTask) => {
  const data = payload(
    await publicRequest.post('claim-coins', {method_id: Number(task.serverId)}),
  );
  return {
    balance: Number(data.new_balance || 0),
    amount: Number(data.earned_amount || task.reward),
  };
};

export type ProductionRewardResult = RewardResult;
export type ProductionCoinTask = CoinTask;
export const claimProductionDailyReward = claimDailyReward;
export const getProductionWallet = getWallet;
export const getProductionCoinPackages = getCoinPackages;
export const getProductionCoinTasks = getCoinTasks;
export const startProductionCoinTask = startCoinTask;
export const claimProductionCoinTask = claimCoinTask;
