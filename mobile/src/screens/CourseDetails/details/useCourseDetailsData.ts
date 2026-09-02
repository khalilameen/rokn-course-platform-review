import {useCallback, useEffect, useRef, useState} from 'react';
import type {Dispatch, SetStateAction} from 'react';
import {
  getDemoExperience,
  subscribeDemoExperience,
} from '../../../services/demoExperience';
import type {
  DemoCoinPackage,
  DemoExperienceState,
} from '../../../services/demoExperience';
import {
  getCoinPackages,
  getCourseDetails,
  getWallet,
  hasSession,
  isCourseUnavailableError,
} from '../../../services/roknApi';
import type {CourseDetails as CourseDetailsDto} from '../../../services/roknApi';
import {
  friendlyNetworkMessage,
  networkFailureKind,
} from '../../../services/networkExperience';
import {CAN_START_NATIVE_CHECKOUT} from '../../../constants/distribution';
import {subscribeCoinCheckoutCredits} from '../../../services/coinCheckout';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../../constants/helpers';

type UseCourseDetailsDataParams = {
  courseId: string;
  identityKey: string;
  isDemoCourse: boolean;
  setNotice: Dispatch<SetStateAction<string>>;
};

export const useCourseDetailsData = ({
  courseId,
  identityKey,
  isDemoCourse,
  setNotice,
}: UseCourseDetailsDataParams) => {
  const [experience, setExperience] = useState<DemoExperienceState | null>(
    null,
  );
  const [remoteBalance, setRemoteBalance] = useState<number | null>(null);
  const [remoteSpendableBalance, setRemoteSpendableBalance] = useState<
    number | null
  >(null);
  const [remotePaidBalance, setRemotePaidBalance] = useState<number | null>(null);
  const [remoteRewardBalance, setRemoteRewardBalance] = useState<number | null>(null);
  const [remoteRewardContributionCap, setRemoteRewardContributionCap] =
    useState<number | null>(null);
  const [remoteOwned, setRemoteOwned] = useState(false);
  const [remotePackages, setRemotePackages] = useState<DemoCoinPackage[]>([]);
  const [remoteSession, setRemoteSession] = useState<boolean | null>(null);
  const [remoteCourse, setRemoteCourse] =
    useState<CourseDetailsDto | null>(null);
  const loadedCourseRef = useRef<CourseDetailsDto | null>(null);
  const loadedOwnerRef = useRef(identityKey);
  const displayScopeRef = useRef({courseId, identityKey});
  const [remoteLoading, setRemoteLoading] = useState(!isDemoCourse);
  const [remoteError, setRemoteError] = useState('');
  const [remoteReload, setRemoteReload] = useState(0);
  const reloadRemote = useCallback(
    () => setRemoteReload(value => value + 1),
    [],
  );

  useEffect(() => {
    if (!isDemoCourse) return undefined;
    void getDemoExperience().then(setExperience);
    return subscribeDemoExperience(setExperience);
  }, [isDemoCourse]);

  useEffect(() => {
    let active = true;
    const controller = new AbortController();
    if (isDemoCourse)
      return () => {
        active = false;
        controller.abort();
      };
    if (
      displayScopeRef.current.courseId !== courseId ||
      displayScopeRef.current.identityKey !== identityKey
    ) {
      displayScopeRef.current = {courseId, identityKey};
      setRemoteCourse(null);
      setRemoteBalance(null);
      setRemoteSpendableBalance(null);
      setRemotePaidBalance(null);
      setRemoteRewardBalance(null);
      setRemoteRewardContributionCap(null);
      setRemotePackages([]);
      setRemoteOwned(false);
      setRemoteError('');
      setRemoteLoading(true);
    }
    if (!courseId) {
      setRemoteLoading(false);
      setRemoteError('عد إلى الرئيسية\nوافتح الكورس من هناك');
      return () => {
        active = false;
        controller.abort();
      };
    }
    void (async () => {
      const boundary = await captureAccountSessionBoundary().catch(() => null);
      if (!boundary) {
        if (active) {
          setRemoteLoading(false);
          setRemoteError('تعذّر تجهيز تفاصيل الكورس\nحاول مرة أخرى');
        }
        return;
      }
      if (!active) return;
      const stillOwned = () => {
        if (!active) return false;
        try {
          assertAccountSessionBoundary(boundary);
          return true;
        } catch {
          setRemoteLoading(false);
          setRemoteError('تغيّر الحساب\nحاول مرة أخرى');
          return false;
        }
      };
      const hasCurrentDetails =
        loadedCourseRef.current?.id === courseId &&
        loadedOwnerRef.current === identityKey;
      setRemoteLoading(!hasCurrentDetails);
      setRemoteError('');
      setNotice('');
      if (
        loadedCourseRef.current?.id !== courseId ||
        loadedOwnerRef.current !== identityKey
      ) {
        loadedCourseRef.current = null;
        loadedOwnerRef.current = identityKey;
        setRemoteCourse(null);
        setRemoteBalance(null);
        setRemoteSpendableBalance(null);
        setRemotePaidBalance(null);
        setRemoteRewardBalance(null);
        setRemoteRewardContributionCap(null);
        setRemotePackages([]);
        setRemoteOwned(false);
      }
      const sessionAvailable = await hasSession();
      if (!stillOwned()) return;
      setRemoteSession(sessionAvailable);
      let detailsLoaded =
        loadedCourseRef.current?.id === courseId &&
        loadedOwnerRef.current === identityKey;
      try {
        const details = await getCourseDetails(courseId, {
          signal: controller.signal,
        });
        detailsLoaded = true;
        if (stillOwned()) {
          loadedCourseRef.current = details;
          loadedOwnerRef.current = identityKey;
          setRemoteCourse(details);
          setRemoteOwned(details.owned);
          if (details.fromCache) {
            setNotice('نعرض آخر تفاصيل محفوظة\nسنحدّثها عند عودة الاتصال');
          }
        }
      } catch (error) {
        if (networkFailureKind(error) === 'cancelled') return;
        if (stillOwned()) {
          if (isCourseUnavailableError(error)) {
            loadedCourseRef.current = null;
            setRemoteCourse(null);
            setRemoteOwned(false);
            detailsLoaded = false;
            setRemoteError('هذا الكورس غير متاح الآن\nعد إلى الرئيسية واختر كورسًا آخر');
          }
          if (!isCourseUnavailableError(error)) {
            const message = friendlyNetworkMessage(error, 'تفاصيل الكورس');
            if (detailsLoaded) setNotice(message);
            else setRemoteError(message);
          }
        }
      }
      if (!detailsLoaded) {
        if (stillOwned()) setRemoteLoading(false);
        return;
      }
      if (sessionAvailable) {
        const [walletResult, packagesResult] = await Promise.allSettled([
          getWallet(),
          getCoinPackages(),
        ]);
        if (!stillOwned()) return;
        if (walletResult.status === 'fulfilled') {
          setRemoteBalance(walletResult.value.balance);
          setRemoteSpendableBalance(walletResult.value.spendableBalance);
          setRemotePaidBalance(walletResult.value.paidBalance);
          setRemoteRewardBalance(walletResult.value.rewardBalance);
          setRemoteRewardContributionCap(
            walletResult.value.rewardContributionCap,
          );
        }
        if (packagesResult.status === 'fulfilled') {
          setRemotePackages(packagesResult.value);
        }
        if (
          walletResult.status === 'rejected' ||
          packagesResult.status === 'rejected'
        ) {
          setNotice('تعذّر تحديث بعض بيانات المحفظة\nحدّث الصفحة لعرض أحدثها');
        }
      }
      if (stillOwned()) setRemoteLoading(false);
    })();
    return () => {
      active = false;
      controller.abort();
    };
  }, [courseId, identityKey, isDemoCourse, remoteReload, setNotice]);

  useEffect(() => {
    if (isDemoCourse) return undefined;
    let active = true;
    let unsubscribe: () => void = () => undefined;
    const reloadAfterCredit = () => setRemoteReload(value => value + 1);
    const unsubscribeExternal = subscribeCoinCheckoutCredits(reloadAfterCredit);
    if (CAN_START_NATIVE_CHECKOUT) {
      void import('../../../services/nativeStoreBilling').then(storeBilling => {
        if (!active) return;
        unsubscribe = storeBilling.subscribeNativeStoreCredits(reloadAfterCredit);
      });
    }
    return () => {
      active = false;
      unsubscribe();
      unsubscribeExternal();
    };
  }, [isDemoCourse]);

  const ownerMatches =
    displayScopeRef.current.identityKey === identityKey &&
    displayScopeRef.current.courseId === courseId;

  return {
    experience,
    reloadRemote,
    remoteBalance: ownerMatches ? remoteBalance : null,
    remoteCourse: ownerMatches ? remoteCourse : null,
    remoteError: ownerMatches ? remoteError : '',
    remoteLoading: remoteLoading || !ownerMatches,
    remoteOwned: ownerMatches ? remoteOwned : false,
    remotePaidBalance: ownerMatches ? remotePaidBalance : null,
    remotePackages: ownerMatches ? remotePackages : [],
    remoteSession: ownerMatches ? remoteSession : null,
    remoteRewardBalance: ownerMatches ? remoteRewardBalance : null,
    remoteRewardContributionCap: ownerMatches
      ? remoteRewardContributionCap
      : null,
    remoteSpendableBalance: ownerMatches ? remoteSpendableBalance : null,
    setExperience,
    setRemoteBalance,
    setRemoteCourse,
    setRemoteOwned,
    setRemotePaidBalance,
    setRemotePackages,
    setRemoteSpendableBalance,
    setRemoteRewardBalance,
  };
};
