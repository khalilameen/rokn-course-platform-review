import AsyncStorage from '@react-native-async-storage/async-storage';
import {cappedRewardCredit, ECONOMY_CONFIG} from '../config/economy';
import {accountScopedStorageKey} from '../constants/helpers';
import {roknCalendarDay} from '../constants/roknCalendar';
import {serverNowMs} from '../utils/serverClock';
import {LOCAL_DEMO_ENABLED} from '../config/runtime';

const assertLocalDemoEnabled = () => {
  if (!LOCAL_DEMO_ENABLED) throw new Error('LOCAL_DEMO_DISABLED');
};

export const DEMO_COURSE_ID = 'demo-freelance-course';
export const DEMO_COURSE_PRICE = ECONOMY_CONFIG.minimumCoursePrice;

export type DemoTaskStatus = 'available' | 'started' | 'claimed';

export type DemoCoinTask = {
  id: string;
  title: string;
  description: string;
  reward: number;
  url?: string;
  status: DemoTaskStatus;
};

export type DemoCoinPackage = {
  id: string;
  coins: number;
  price: number;
  label: string;
  recommended?: boolean;
  displayPrice?: string;
  storeProductIds?: {
    google?: string;
    apple?: string;
  };
};

export type DemoTransaction = {
  id: string;
  title: string;
  amount: number;
  createdAt: number;
  reference?: string;
  source?: 'paid' | 'reward' | 'mixed' | 'legacy';
  paidAmount?: number;
  rewardAmount?: number;
};

export type DemoRewardEventType =
  | 'daily'
  | 'study'
  | 'first_project'
  | 'course_completion';

export type DemoRewardEvent = {
  key: string;
  type: DemoRewardEventType;
  amount: number;
  createdAt: number;
  day: string;
  courseId?: string;
};

type DemoStudyDay = {
  seconds: number;
  updatedAt: number;
};

export type DemoRewardLedger = {
  /** Only rolling-window events live here; old rows are pruned on every write. */
  events: DemoRewardEvent[];
  /** Permanent keys prevent duplicate one-time rewards. */
  lifetimeKeys: string[];
  /** At most the latest 30 local days; no playback or chat content is stored. */
  studyDays: Record<string, DemoStudyDay>;
};

export type DemoExperienceState = {
  version: 5;
  balance: number;
  paidBalance: number;
  rewardBalance: number;
  purchasedCourseIds: string[];
  coursePlanCodes: Record<string, 'basic' | 'guided' | 'mentor' | 'grant'>;
  tasks: DemoCoinTask[];
  transactions: DemoTransaction[];
  rewardLedger: DemoRewardLedger;
};

export const DEMO_COIN_PACKAGES: DemoCoinPackage[] = [
  ...ECONOMY_CONFIG.packages.map(item => ({...item})),
];

const STORAGE_KEY = '@rokn/demo-experience/v1';
const DAY_MS = 24 * 60 * 60 * 1000;
const ROLLING_WINDOW_MS = 30 * DAY_MS;
const MAX_DEMO_TRANSACTIONS = 250;
const listeners = new Set<(state: DemoExperienceState) => void>();
const writeQueues = new Map<string, Promise<unknown>>();

const currentStorageKey = () => accountScopedStorageKey(STORAGE_KEY);

const localDayKey = (date = new Date()) => roknCalendarDay(date);

const emptyRewardLedger = (): DemoRewardLedger => ({
  events: [],
  lifetimeKeys: [],
  studyDays: {},
});

const compactDemoState = (state: DemoExperienceState): DemoExperienceState => ({
  ...state,
  transactions: state.transactions.slice(0, MAX_DEMO_TRANSACTIONS),
});

const normalizeRewardLedger = (
  value: unknown,
  now = Date.now(),
): DemoRewardLedger => {
  const raw = (
    value && typeof value === 'object' ? value : {}
  ) as Partial<DemoRewardLedger>;
  const cutoff = now - ROLLING_WINDOW_MS;
  const events = Array.isArray(raw.events)
    ? raw.events.filter(
        event =>
          event &&
          typeof event.key === 'string' &&
          ['daily', 'study', 'first_project', 'course_completion'].includes(
            String(event.type),
          ) &&
          Number.isFinite(event.amount) &&
          Number.isFinite(event.createdAt) &&
          event.createdAt >= cutoff,
      )
    : [];
  const lifetimeKeys = Array.isArray(raw.lifetimeKeys)
    ? Array.from(
        new Set(raw.lifetimeKeys.filter(key => typeof key === 'string')),
      ).slice(-250)
    : [];
  const studyDays = Object.entries(raw.studyDays || {}).reduce<
    Record<string, DemoStudyDay>
  >((result, [day, entry]) => {
    if (
      entry &&
      Number.isFinite(entry.seconds) &&
      Number.isFinite(entry.updatedAt) &&
      entry.updatedAt >= cutoff
    ) {
      result[day] = {
        seconds: Math.max(0, Math.min(DAY_MS / 1000, Number(entry.seconds))),
        updatedAt: Number(entry.updatedAt),
      };
    }
    return result;
  }, {});
  return {events, lifetimeKeys, studyDays};
};

const COIN_GUIDE_TASK = {
  id: 'coin-guide',
  title: 'تعرّف إلى رصيد ركن',
  description: 'اعرف كيف تستخدم عملاتك',
  reward: ECONOMY_CONFIG.rewards.coinGuide,
};

const normalizeDemoTasks = (tasks: DemoCoinTask[]): DemoCoinTask[] =>
  tasks.map(task => {
    if (task.id === 'notifications' || task.id === 'coin-guide') {
      // Preserve the old task state so an already claimed permission reward
      // cannot be claimed again after it becomes the in-app balance guide.
      return {...task, ...COIN_GUIDE_TASK};
    }
    if (task.id === 'instagram') {
      return {
        ...task,
        reward: ECONOMY_CONFIG.rewards.externalTask,
        title: 'تابع ركن على Instagram',
        description: '',
      };
    }
    if (task.id === 'tiktok') {
      return {
        ...task,
        reward: ECONOMY_CONFIG.rewards.externalTask,
        title: 'تابع ركن على TikTok',
        description: '',
      };
    }
    if (task.id === 'youtube') {
      return {
        ...task,
        reward: ECONOMY_CONFIG.rewards.externalTask,
        title: 'تابع ركن على YouTube',
        description: '',
      };
    }
    return task;
  });

const initialState = (): DemoExperienceState => ({
  version: 5,
  balance: ECONOMY_CONFIG.welcomeReward,
  paidBalance: 0,
  rewardBalance: ECONOMY_CONFIG.welcomeReward,
  purchasedCourseIds: [],
  coursePlanCodes: {},
  tasks: [
    {
      ...COIN_GUIDE_TASK,
      status: 'available',
    },
    {
      id: 'instagram',
      title: 'تابع ركن على Instagram',
      description: '',
      reward: ECONOMY_CONFIG.rewards.externalTask,
      url: 'https://www.instagram.com/rokn.learn/',
      status: 'available',
    },
    {
      id: 'tiktok',
      title: 'تابع ركن على TikTok',
      description: '',
      reward: ECONOMY_CONFIG.rewards.externalTask,
      url: 'https://www.tiktok.com/@rokn.learn',
      status: 'available',
    },
    {
      id: 'youtube',
      title: 'تابع ركن على YouTube',
      description: '',
      reward: ECONOMY_CONFIG.rewards.externalTask,
      url: 'https://www.youtube.com/@roknlearn',
      status: 'available',
    },
  ],
  transactions: [
    {
      id: 'welcome-coins',
      title: 'هدية البداية',
      amount: ECONOMY_CONFIG.welcomeReward,
      createdAt: Date.now(),
      reference: 'welcome-v1',
      source: 'reward',
      rewardAmount: ECONOMY_CONFIG.welcomeReward,
    },
  ],
  rewardLedger: emptyRewardLedger(),
});

const parseState = (value: string | null): DemoExperienceState => {
  if (!value) return initialState();
  try {
    const parsed = JSON.parse(value) as Omit<
      Partial<DemoExperienceState>,
      'version'
    > & {
      version?: number;
    };
    if (
      parsed?.version === 1 &&
      Number.isFinite(parsed.balance) &&
      Array.isArray(parsed.tasks) &&
      Array.isArray(parsed.transactions) &&
      Array.isArray(parsed.purchasedCourseIds)
    ) {
      // A legacy balance had no auditable funding source. Treating it as paid
      // would invent revenue, so it is migrated conservatively as rewards.
      const legacyBalance = Math.min(
        ECONOMY_CONFIG.rewardBalanceCap,
        Math.max(0, Number(parsed.balance)),
      );
      return compactDemoState({
        version: 5,
        balance: legacyBalance,
        paidBalance: 0,
        rewardBalance: legacyBalance,
        purchasedCourseIds: parsed.purchasedCourseIds,
        coursePlanCodes: Object.fromEntries(
          parsed.purchasedCourseIds.map(courseId => [courseId, 'mentor']),
        ),
        tasks: normalizeDemoTasks(parsed.tasks),
        transactions: parsed.transactions.map(item => ({
          ...item,
          source: item.source ?? 'legacy',
        })),
        rewardLedger: emptyRewardLedger(),
      });
    }
    if (
      ![2, 3, 4, 5].includes(Number(parsed?.version)) ||
      !Number.isFinite(parsed.balance) ||
      !Number.isFinite(parsed.paidBalance) ||
      !Number.isFinite(parsed.rewardBalance) ||
      !Array.isArray(parsed.tasks) ||
      !Array.isArray(parsed.transactions) ||
      !Array.isArray(parsed.purchasedCourseIds)
    ) {
      return initialState();
    }
    const paidBalance = Math.max(0, Number(parsed.paidBalance));
    const rewardBalance = Math.min(
      ECONOMY_CONFIG.rewardBalanceCap,
      Math.max(0, Number(parsed.rewardBalance)),
    );
    const untouchedOldWelcome =
      parsed.version !== 4 &&
      paidBalance === 0 &&
      parsed.purchasedCourseIds.length === 0 &&
      parsed.tasks.every(task => task.status === 'available') &&
      parsed.transactions.length === 1 &&
      parsed.transactions[0]?.id === 'welcome-coins';
    if (untouchedOldWelcome) {
      return initialState();
    }
    return compactDemoState({
      ...(parsed as DemoExperienceState),
      version: 5,
      balance: paidBalance + rewardBalance,
      paidBalance,
      rewardBalance,
      coursePlanCodes:
        parsed.coursePlanCodes && typeof parsed.coursePlanCodes === 'object'
          ? parsed.coursePlanCodes
          : Object.fromEntries(
              parsed.purchasedCourseIds.map(courseId => [courseId, 'mentor']),
            ),
      tasks: normalizeDemoTasks(parsed.tasks),
      rewardLedger: normalizeRewardLedger(
        (parsed as Partial<DemoExperienceState>).rewardLedger,
      ),
    });
  } catch {
    return initialState();
  }
};

const emit = (state: DemoExperienceState) => {
  listeners.forEach(listener => listener(state));
};

export const getDemoExperience = async (): Promise<DemoExperienceState> => {
  assertLocalDemoEnabled();
  const storageKey = await currentStorageKey();
  const raw = await AsyncStorage.getItem(storageKey);
  const state = parseState(raw);
  const normalized = JSON.stringify(state);
  if (raw !== normalized) {
    await AsyncStorage.setItem(storageKey, normalized);
  }
  return state;
};

const updateDemoExperience = (
  updater: (current: DemoExperienceState) => DemoExperienceState,
): Promise<DemoExperienceState> => {
  try {
    assertLocalDemoEnabled();
  } catch (error) {
    return Promise.reject(error);
  }
  const operation = currentStorageKey().then(storageKey => {
    const previous = writeQueues.get(storageKey) ?? Promise.resolve();
    const nextWrite = previous.then(async () => {
      const current = parseState(await AsyncStorage.getItem(storageKey));
      const next = compactDemoState(updater(current));
      await AsyncStorage.setItem(storageKey, JSON.stringify(next));
      if ((await currentStorageKey()) === storageKey) {
        emit(next);
      }
      return next;
    });
    const tail = nextWrite.then(
      () => undefined,
      () => undefined,
    );
    writeQueues.set(storageKey, tail);
    void tail.then(() => {
      if (writeQueues.get(storageKey) === tail) writeQueues.delete(storageKey);
    });
    return nextWrite;
  });
  return operation.then(state => state);
};

export const subscribeDemoExperience = (
  listener: (state: DemoExperienceState) => void,
) => {
  assertLocalDemoEnabled();
  listeners.add(listener);
  void currentStorageKey().then(async storageKey => {
    const state = parseState(await AsyncStorage.getItem(storageKey));
    if ((await currentStorageKey()) === storageKey && listeners.has(listener)) {
      listener(state);
    }
  });
  return () => {
    listeners.delete(listener);
  };
};

type DemoRewardCredit = {
  key: string;
  type: DemoRewardEventType;
  requested: number;
  rollingCap: number;
  title: string;
  courseId?: string;
  lifetime?: boolean;
  now?: number;
};

const applyDemoRewardCredit = (
  current: DemoExperienceState,
  credit: DemoRewardCredit,
): DemoExperienceState => {
  const now = credit.now ?? Date.now();
  const ledger = normalizeRewardLedger(current.rewardLedger, now);
  if (
    ledger.events.some(event => event.key === credit.key) ||
    ledger.lifetimeKeys.includes(credit.key)
  ) {
    return {...current, rewardLedger: ledger};
  }
  const rollingTotal = ledger.events
    .filter(event => event.type === credit.type)
    .reduce((total, event) => total + event.amount, 0);
  const withinRule = Math.min(
    Math.max(0, credit.requested),
    Math.max(0, credit.rollingCap - rollingTotal),
  );
  const awarded = cappedRewardCredit(current.rewardBalance, withinRule);
  const day = localDayKey(new Date(now));
  const rewardLedger: DemoRewardLedger = {
    ...ledger,
    events: [
      ...ledger.events,
      {
        key: credit.key,
        type: credit.type,
        amount: awarded,
        createdAt: now,
        day,
        courseId: credit.courseId,
      },
    ],
    lifetimeKeys: credit.lifetime
      ? Array.from(new Set([...ledger.lifetimeKeys, credit.key])).slice(-250)
      : ledger.lifetimeKeys,
  };
  return {
    ...current,
    balance: current.balance + awarded,
    rewardBalance: current.rewardBalance + awarded,
    rewardLedger,
    transactions:
      awarded > 0
        ? [
            {
              id: `reward:${credit.key}:${now}`,
              title: credit.title,
              amount: awarded,
              createdAt: now,
              reference: `reward:${credit.key}`,
              source: 'reward',
              rewardAmount: awarded,
            },
            ...current.transactions,
          ]
        : current.transactions,
  };
};

/** Local review identity; the deployed service issues this event on the server. */
export const claimDemoDailyReward = async () => {
  const now = serverNowMs();
  const day = localDayKey(new Date(now));
  return updateDemoExperience(current =>
    applyDemoRewardCredit(current, {
      key: `daily:${day}`,
      type: 'daily',
      requested: Math.min(
        ECONOMY_CONFIG.rewards.daily.amount,
        ECONOMY_CONFIG.rewards.daily.dailyCap,
      ),
      rollingCap: ECONOMY_CONFIG.rewards.daily.rolling30DayCap,
      title: 'مكافأة الرجوع اليومي',
      now,
    }),
  );
};

/**
 * Adds qualified foreground watch time in small batches. Only day totals and
 * compact event keys are stored; no playback samples or content are retained.
 */
export const recordDemoQualifiedStudy = async (qualifiedSeconds: number) => {
  const addedSeconds = Math.max(
    0,
    Math.min(120, Number(qualifiedSeconds) || 0),
  );
  if (!addedSeconds) return getDemoExperience();
  const now = serverNowMs();
  const day = localDayKey(new Date(now));
  return updateDemoExperience(current => {
    const ledger = normalizeRewardLedger(current.rewardLedger, now);
    const previous = ledger.studyDays[day]?.seconds || 0;
    const seconds = previous + addedSeconds;
    let next: DemoExperienceState = {
      ...current,
      rewardLedger: {
        ...ledger,
        studyDays: {
          ...ledger.studyDays,
          [day]: {seconds, updatedAt: now},
        },
      },
    };
    const reward = ECONOMY_CONFIG.rewards.study;
    const earnedSlots = Math.floor(
      seconds / (reward.minimumQualifiedMinutes * 60),
    );
    const dailySlots = Math.floor(reward.dailyCap / reward.amount);
    const recordedSlots = ledger.events.filter(
      event => event.type === 'study' && event.day === day,
    ).length;
    const slotsToRecord = Math.max(
      0,
      Math.min(earnedSlots, dailySlots) - recordedSlots,
    );
    for (let offset = 0; offset < slotsToRecord; offset += 1) {
      const sequence = recordedSlots + offset + 1;
      next = applyDemoRewardCredit(next, {
        key: `study:${day}:${sequence}`,
        type: 'study',
        requested: reward.amount,
        rollingCap: reward.rolling30DayCap,
        title: 'مكافأة وقت التعلّم',
        now: now + offset,
      });
    }
    return next;
  });
};

export const claimDemoFirstProjectReward = async (_projectId: string) =>
  updateDemoExperience(current =>
    applyDemoRewardCredit(current, {
      key: 'first-project-passed',
      type: 'first_project',
      requested: ECONOMY_CONFIG.rewards.firstProject.amount,
      rollingCap: ECONOMY_CONFIG.rewards.firstProject.lifetimeCap,
      title: 'مكافأة أول مشروع معتمد',
      courseId: DEMO_COURSE_ID,
      lifetime: true,
    }),
  );

export const claimDemoCourseCompletionReward = async (
  courseId = DEMO_COURSE_ID,
) =>
  updateDemoExperience(current =>
    applyDemoRewardCredit(current, {
      key: `course-completion:${courseId}`,
      type: 'course_completion',
      requested: ECONOMY_CONFIG.rewards.courseCompletion.amount,
      rollingCap: ECONOMY_CONFIG.rewards.courseCompletion.rolling30DayCap,
      title: 'مكافأة إتمام الكورس وإصدار الشهادة',
      courseId,
      lifetime: true,
    }),
  );

export const beginDemoTask = async (taskId: string) =>
  updateDemoExperience(current => ({
    ...current,
    tasks: current.tasks.map(task =>
      task.id === taskId && task.status === 'available'
        ? {...task, status: 'started'}
        : task,
    ),
  }));

export const claimDemoTask = async (taskId: string) =>
  updateDemoExperience(current => {
    const task = current.tasks.find(item => item.id === taskId);
    if (!task || task.status !== 'started') return current;
    const reference = `task:${task.id}`;
    if (current.transactions.some(item => item.reference === reference)) {
      return {
        ...current,
        tasks: current.tasks.map(item =>
          item.id === taskId ? {...item, status: 'claimed'} : item,
        ),
      };
    }
    const creditedReward = cappedRewardCredit(
      current.rewardBalance,
      task.reward,
    );
    return {
      ...current,
      balance: current.balance + creditedReward,
      rewardBalance: current.rewardBalance + creditedReward,
      tasks: current.tasks.map(item =>
        item.id === taskId ? {...item, status: 'claimed'} : item,
      ),
      transactions:
        creditedReward > 0
          ? [
              {
                id: `${reference}:${Date.now()}`,
                title: task.title,
                amount: creditedReward,
                createdAt: Date.now(),
                reference,
                source: 'reward',
                rewardAmount: creditedReward,
              },
              ...current.transactions,
            ]
          : current.transactions,
    };
  });

export const creditDemoCoins = async (
  coins: number,
  title: string,
  reference: string,
) =>
  updateDemoExperience(current => {
    if (
      coins <= 0 ||
      current.transactions.some(item => item.reference === reference)
    ) {
      return current;
    }
    return {
      ...current,
      balance: current.balance + coins,
      paidBalance: current.paidBalance + coins,
      transactions: [
        {
          id: `${reference}:${Date.now()}`,
          title,
          amount: coins,
          createdAt: Date.now(),
          reference,
          source: 'paid',
          paidAmount: coins,
        },
        ...current.transactions,
      ],
    };
  });

export const purchaseDemoCourse = async (
  courseId: string = DEMO_COURSE_ID,
  price: number = DEMO_COURSE_PRICE,
  planCode: 'basic' | 'guided' | 'mentor' = 'basic',
): Promise<{purchased: boolean; state: DemoExperienceState}> => {
  let purchased = false;
  const state = await updateDemoExperience(current => {
    if (current.purchasedCourseIds.includes(courseId)) {
      purchased = true;
      return current;
    }
    if (current.balance < price) return current;
    // Rewards are spent first. This preserves coins bought with real money for
    // the learner and prevents the dashboard from overstating paid redemption.
    const rewardAmount = Math.min(
      current.rewardBalance,
      price,
      ECONOMY_CONFIG.maxRewardContributionPerCourse,
    );
    const paidAmount = price - rewardAmount;
    purchased = true;
    return {
      ...current,
      balance: current.balance - price,
      rewardBalance: current.rewardBalance - rewardAmount,
      paidBalance: current.paidBalance - paidAmount,
      purchasedCourseIds: [...current.purchasedCourseIds, courseId],
      coursePlanCodes: {...current.coursePlanCodes, [courseId]: planCode},
      transactions: [
        {
          id: `course:${courseId}:${Date.now()}`,
          title: 'فتح كورس من أول مهارة إلى أول عميل',
          amount: -price,
          createdAt: Date.now(),
          reference: `course:${courseId}`,
          source:
            paidAmount && rewardAmount
              ? 'mixed'
              : paidAmount
              ? 'paid'
              : 'reward',
          paidAmount,
          rewardAmount,
        },
        ...current.transactions,
      ],
    };
  });
  return {purchased, state};
};

export const redeemDemoCourseCode = async (
  code: string,
  courseId = DEMO_COURSE_ID,
): Promise<{redeemed: boolean; state: DemoExperienceState}> => {
  const normalizedCode = code.trim().toUpperCase();
  let redeemed = false;
  const state = await updateDemoExperience(current => {
    if (normalizedCode !== 'ROKN-COLLEGE') return current;
    redeemed = true;
    if (current.purchasedCourseIds.includes(courseId)) return current;
    return {
      ...current,
      purchasedCourseIds: [...current.purchasedCourseIds, courseId],
      coursePlanCodes: {...current.coursePlanCodes, [courseId]: 'grant'},
      transactions: [
        {
          id: `course-code:${courseId}:${Date.now()}`,
          title: 'فتح الكورس بكود جهة تعليمية',
          amount: 0,
          createdAt: Date.now(),
          reference: `course-code:${normalizedCode}`,
          source: 'reward',
          rewardAmount: 0,
        },
        ...current.transactions,
      ],
    };
  });
  return {redeemed, state};
};

export const hasDemoCourseAccess = async (courseId = DEMO_COURSE_ID) =>
  (await getDemoExperience()).purchasedCourseIds.includes(courseId);

export const getDemoCoursePlanCode = async (courseId = DEMO_COURSE_ID) =>
  (await getDemoExperience()).coursePlanCodes[courseId];
