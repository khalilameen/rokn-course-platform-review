import AsyncStorage from '@react-native-async-storage/async-storage';
import {publicRequest} from '../../constants/api';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {learnerFacingText} from '../../utils/errorPayload';
import type {DemoCoinPackage} from '../demoExperience';
import {
  firstBoolean,
  isApiRecord,
  nonNegativeNumber,
  payload,
  resourceList,
  requireNonNegativeNumber,
} from './common';
import {IS_STORE_DISTRIBUTION} from '../../constants/distribution';
import {mapCoinPackages} from './coinPackageMapper';

const COIN_TASK_ACTIONS_KEY = '@rokn/coin-task-actions/v1';
let coinTaskActionStorageTail: Promise<void> = Promise.resolve();

const withCoinTaskActionStorageLock = <T>(operation: () => Promise<T>) => {
  const result = coinTaskActionStorageTail.then(operation, operation);
  coinTaskActionStorageTail = result.then(
    () => undefined,
    () => undefined,
  );
  return result;
};

const readCoinTaskActionUrls = async (
  boundary?: AccountSessionBoundary,
): Promise<Record<string, string>> => {
  if (boundary) assertAccountSessionBoundary(boundary);
  const key = await accountScopedStorageKey(COIN_TASK_ACTIONS_KEY, boundary);
  const raw = await AsyncStorage.getItem(key);
  if (boundary) assertAccountSessionBoundary(boundary);
  if (!raw) return {};
  try {
    const parsed: unknown = JSON.parse(raw);
    if (!isApiRecord(parsed)) return {};
    return Object.fromEntries(
      Object.entries(parsed).flatMap(([taskId, value]) =>
        /^\d+$/.test(taskId) &&
        typeof value === 'string' &&
        value.length <= 4096
          ? [[taskId, value]]
          : [],
      ),
    );
  } catch {
    return {};
  }
};

const rememberCoinTaskActionUrl = async (
  taskId: string,
  url?: string,
  boundary?: AccountSessionBoundary,
) =>
  withCoinTaskActionStorageLock(async () => {
    if (!/^\d+$/.test(taskId) || !url) return;
    if (boundary) assertAccountSessionBoundary(boundary);
    const key = await accountScopedStorageKey(COIN_TASK_ACTIONS_KEY, boundary);
    const urls = await readCoinTaskActionUrls(boundary);
    urls[taskId] = url;
    if (boundary) assertAccountSessionBoundary(boundary);
    await AsyncStorage.setItem(key, JSON.stringify(urls));
    if (boundary) assertAccountSessionBoundary(boundary);
  });

const forgetCoinTaskActionUrl = async (
  taskId: string,
  boundary?: AccountSessionBoundary,
) =>
  withCoinTaskActionStorageLock(async () => {
    if (boundary) assertAccountSessionBoundary(boundary);
    const key = await accountScopedStorageKey(COIN_TASK_ACTIONS_KEY, boundary);
    const urls = await readCoinTaskActionUrls(boundary);
    if (!(taskId in urls)) return;
    delete urls[taskId];
    if (boundary) assertAccountSessionBoundary(boundary);
    await AsyncStorage.setItem(key, JSON.stringify(urls));
    if (boundary) assertAccountSessionBoundary(boundary);
  });

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

const firstDefined = (...values: unknown[]) =>
  values.find(value => value !== undefined && value !== null);

const optionalNonNegativeNumber = (
  value: unknown,
  fallback: number,
  field: string,
) =>
  value === undefined || value === null
    ? fallback
    : requireNonNegativeNumber(value, field);

const financialSnapshot = (data: WalletDto) => {
  const balance = requireNonNegativeNumber(
    firstDefined(
      data.total_balance,
      data.breakdown?.total_balance,
      data.balance,
    ),
    'WALLET_TOTAL_BALANCE',
  );
  const paidBalance = optionalNonNegativeNumber(
    firstDefined(
      data.paid_balance,
      data.purchased_balance,
      data.breakdown?.paid_balance,
      data.breakdown?.purchased_balance,
    ),
    0,
    'WALLET_PAID_BALANCE',
  );
  const rewardBalance = optionalNonNegativeNumber(
    firstDefined(data.reward_balance, data.breakdown?.reward_balance),
    balance - paidBalance,
    'WALLET_REWARD_BALANCE',
  );
  const spendableBalance = optionalNonNegativeNumber(
    firstDefined(
      data.course_spendable_balance,
      data.spendable_balance,
      data.breakdown?.course_spendable_balance,
    ),
    balance,
    'WALLET_SPENDABLE_BALANCE',
  );

  if (
    paidBalance > balance ||
    rewardBalance > balance ||
    paidBalance + rewardBalance !== balance ||
    spendableBalance > balance
  ) {
    throw new Error('API_CONTRACT_INVALID_WALLET_BREAKDOWN');
  }

  return {balance, paidBalance, rewardBalance, spendableBalance};
};

export const claimDailyReward = async (): Promise<RewardResult> => {
  const data = payload(await publicRequest.post('rewards/daily'));
  return {
    awarded: requireNonNegativeNumber(data.awarded, 'REWARD_AWARDED'),
    balance: requireNonNegativeNumber(data.balance, 'REWARD_BALANCE'),
    rewardBalance: requireNonNegativeNumber(
      data.reward_balance,
      'REWARD_BUCKET_BALANCE',
    ),
  };
};

export const getWallet = async (): Promise<WalletSnapshot> => {
  const data = payload<WalletDto>(await publicRequest.get('wallet'));
  const {balance, paidBalance, rewardBalance, spendableBalance} =
    financialSnapshot(data);
  return {
    balance,
    paidBalance,
    rewardBalance,
    spendableBalance,
    rewardContributionCap: requireNonNegativeNumber(
      firstDefined(
        data.reward_contribution_cap_per_course,
        data.breakdown?.reward_contribution_cap_per_course,
      ),
      'WALLET_REWARD_CONTRIBUTION_CAP',
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
      ? data.recent_transactions.flatMap(item => {
          const id = String(item.id ?? '').trim();
          const amount = nonNegativeNumber(item.amount);
          if (!id || amount === null) return [];
          return [
            {
              id,
              amount:
                String(item.direction).toLowerCase() === 'debit'
                  ? -amount
                  : amount,
              occurred_at: item.occurred_at,
              category: item.category,
            },
          ];
        })
      : [],
  };
};

export const getCoinPackages = async (): Promise<DemoCoinPackage[]> => {
  const data = payload<unknown>(await publicRequest.get('packages'));
  const items = Array.isArray(data)
    ? data
    : isApiRecord(data)
    ? data.packages
    : (() => {
        throw new Error('API_CONTRACT_INVALID_COIN_PACKAGES');
      })();
  const packages = mapCoinPackages(items, 'API_CONTRACT_INVALID_COIN_PACKAGES');

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

const taskDescription = (actionKey: string) => {
  if (actionKey === 'link_whatsapp') {
    return 'تواصل مع ركن من واتساب';
  }
  if (actionKey.toLowerCase().includes('coin_guide')) {
    return 'اعرف كيف تستخدم عملاتك';
  }
  return '';
};

const truthfulTaskTitle = (title: string, actionKey: string) => {
  const key = actionKey.toLowerCase();
  if (key.includes('coin_guide')) return 'تعرّف إلى رصيد ركن';
  if (key.includes('instagram')) return 'تابع ركن على Instagram';
  if (key.includes('tiktok')) return 'تابع ركن على TikTok';
  if (key.includes('facebook')) return 'تابع ركن على Facebook';
  if (key.includes('youtube')) return 'تابع ركن على YouTube';
  if (key === 'link_whatsapp') return 'اربط واتسابك بركن';
  return title;
};

export const getCoinTasks = async (): Promise<CoinTask[]> => {
  const boundary = await captureAccountSessionBoundary();
  const response = await publicRequest.get('coin-earning-methods');
  assertAccountSessionBoundary(boundary);
  const data = payload<CoinTaskDto[] | {data?: CoinTaskDto[]}>(response);
  const items = resourceList<CoinTaskDto>(data);
  const rememberedUrls = await readCoinTaskActionUrls(boundary);
  const tasks = items.flatMap<CoinTask>(item => {
    const serverId = String(item.id ?? '').trim();
    const reward = nonNegativeNumber(item.coins_amount);
    if (!/^\d+$/.test(serverId) || reward === null || reward <= 0) return [];
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
      : firstBoolean(item.requires_external_visit) ?? false;
    const rawTitle = learnerFacingText(
      item.title_ar || item.title_en,
      'مهمة مكافأة',
    );
    return [
      {
        id: `production-${serverId}`,
        serverId,
        title: truthfulTaskTitle(rawTitle, actionKey),
        description: taskDescription(actionKey),
        reward,
        url: !replacesNotificationReward
          ? item.action_url
            ? String(item.action_url)
            : rememberedUrls[serverId]
          : undefined,
        status:
          state === 'claimed'
            ? 'claimed'
            : state === 'started' || state === 'ready_to_claim'
            ? 'started'
            : 'available',
        actionKey,
        requiresExternalVisit,
      },
    ];
  });
  const activeIds = new Set(
    tasks.filter(task => task.status !== 'claimed').map(task => task.serverId),
  );
  for (const taskId of Object.keys(rememberedUrls)) {
    if (!activeIds.has(taskId)) {
      void forgetCoinTaskActionUrl(taskId, boundary).catch(() => undefined);
    }
  }

  return tasks;
};

export const startCoinTask = async (task: CoinTask) => {
  if (!/^\d+$/.test(task.serverId)) throw new Error('INVALID_COIN_TASK_ID');
  const boundary = await captureAccountSessionBoundary();
  const data = payload(
    await publicRequest.post(`coin-earning-methods/${task.serverId}/start`),
  );
  assertAccountSessionBoundary(boundary);
  const status = String(data.task_state || 'started');
  const url = data.action_url ? String(data.action_url) : task.url;
  if (status === 'claimed') {
    await forgetCoinTaskActionUrl(task.serverId, boundary).catch(
      () => undefined,
    );
  } else {
    await rememberCoinTaskActionUrl(task.serverId, url, boundary).catch(
      () => undefined,
    );
  }

  return {status, url};
};

export const claimCoinTask = async (task: CoinTask) => {
  if (!/^\d+$/.test(task.serverId)) throw new Error('INVALID_COIN_TASK_ID');
  const boundary = await captureAccountSessionBoundary();
  const data = payload(
    await publicRequest.post('claim-coins', {method_id: Number(task.serverId)}),
  );
  assertAccountSessionBoundary(boundary);
  const result = {
    balance: requireNonNegativeNumber(
      data.new_balance,
      'COIN_TASK_NEW_BALANCE',
    ),
    amount: requireNonNegativeNumber(
      data.earned_amount,
      'COIN_TASK_EARNED_AMOUNT',
    ),
  };
  await forgetCoinTaskActionUrl(task.serverId, boundary).catch(() => undefined);
  return result;
};

export type ProductionRewardResult = RewardResult;
export type ProductionCoinTask = CoinTask;
export const claimProductionDailyReward = claimDailyReward;
export const getProductionWallet = getWallet;
export const getProductionCoinPackages = getCoinPackages;
export const getProductionCoinTasks = getCoinTasks;
export const startProductionCoinTask = startCoinTask;
export const claimProductionCoinTask = claimCoinTask;
