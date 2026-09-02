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

type UseCourseDetailsDataParams = {
  courseId: string;
  isDemoCourse: boolean;
  setNotice: Dispatch<SetStateAction<string>>;
};

export const useCourseDetailsData = ({
  courseId,
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
    if (!courseId) {
      setRemoteLoading(false);
      setRemoteError('رابط الكورس غير مكتمل\nعد إلى الرئيسية وافتحه من هناك');
      return () => {
        active = false;
        controller.abort();
      };
    }
    void (async () => {
      setRemoteLoading(true);
      setRemoteError('');
      setNotice('');
      if (loadedCourseRef.current?.id !== courseId) {
        loadedCourseRef.current = null;
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
      if (active) setRemoteSession(sessionAvailable);
      let detailsLoaded = loadedCourseRef.current?.id === courseId;
      try {
        const details = await getCourseDetails(courseId, {
          signal: controller.signal,
        });
        detailsLoaded = true;
        if (active) {
          loadedCourseRef.current = details;
          setRemoteCourse(details);
          setRemoteOwned(details.owned);
          if (details.fromCache) {
            setNotice('نعرض آخر تفاصيل محفوظة\nحدّث الصفحة عند عودة الاتصال');
          }
        }
      } catch (error) {
        if (networkFailureKind(error) === 'cancelled') return;
        if (active) {
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
        if (active) setRemoteLoading(false);
        return;
      }
      if (sessionAvailable) {
        const [walletResult, packagesResult] = await Promise.allSettled([
          getWallet(),
          getCoinPackages(),
        ]);
        if (active && walletResult.status === 'fulfilled') {
          setRemoteBalance(walletResult.value.balance);
          setRemoteSpendableBalance(walletResult.value.spendableBalance);
          setRemotePaidBalance(walletResult.value.paidBalance);
          setRemoteRewardBalance(walletResult.value.rewardBalance);
          setRemoteRewardContributionCap(
            walletResult.value.rewardContributionCap,
          );
        }
        if (active && packagesResult.status === 'fulfilled') {
          setRemotePackages(packagesResult.value);
        }
        if (
          active &&
          (walletResult.status === 'rejected' ||
            packagesResult.status === 'rejected')
        ) {
          setNotice('تعذّر تحديث بعض بيانات المحفظة\nحدّث الصفحة لعرض أحدثها');
        }
      }
      if (active) setRemoteLoading(false);
    })();
    return () => {
      active = false;
      controller.abort();
    };
  }, [courseId, isDemoCourse, remoteReload, setNotice]);

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

  return {
    experience,
    reloadRemote,
    remoteBalance,
    remoteCourse,
    remoteError,
    remoteLoading,
    remoteOwned,
    remotePaidBalance,
    remotePackages,
    remoteSession,
    remoteRewardBalance,
    remoteRewardContributionCap,
    remoteSpendableBalance,
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
