import {useEffect, useState} from 'react';
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
} from '../../../services/roknApi';
import type {CourseDetails as CourseDetailsDto} from '../../../services/roknApi';
import {friendlyNetworkMessage} from '../../../services/networkExperience';
import {CAN_START_NATIVE_CHECKOUT} from '../../../constants/distribution';

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
  const [remoteLoading, setRemoteLoading] = useState(!isDemoCourse);
  const [remoteError, setRemoteError] = useState('');
  const [remoteReload, setRemoteReload] = useState(0);

  useEffect(() => {
    if (!isDemoCourse) return undefined;
    void getDemoExperience().then(setExperience);
    return subscribeDemoExperience(setExperience);
  }, [isDemoCourse]);

  useEffect(() => {
    let active = true;
    if (isDemoCourse)
      return () => {
        active = false;
      };
    if (!courseId) {
      setRemoteLoading(false);
      setRemoteError('رابط الكورس غير مكتمل. ارجع للرئيسية وافتحه من هناك.');
      return () => {
        active = false;
      };
    }
    void (async () => {
      setRemoteLoading(true);
      setRemoteError('');
      setNotice('');
      setRemoteCourse(null);
      setRemoteBalance(null);
      setRemoteSpendableBalance(null);
      setRemotePaidBalance(null);
      setRemoteRewardBalance(null);
      setRemoteRewardContributionCap(null);
      setRemotePackages([]);
      setRemoteOwned(false);
      const sessionAvailable = await hasSession();
      if (active) setRemoteSession(sessionAvailable);
      let detailsLoaded = false;
      try {
        const details = await getCourseDetails(courseId);
        detailsLoaded = true;
        if (active) {
          setRemoteCourse(details);
          setRemoteOwned(details.owned);
        }
      } catch (error) {
        if (active) {
          setRemoteError(friendlyNetworkMessage(error, 'تفاصيل الكورس'));
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
          setNotice('تعذّر جلب بيانات المحفظة\nرصيدك لم يتغير');
        }
      }
      if (active) setRemoteLoading(false);
    })();
    return () => {
      active = false;
    };
  }, [courseId, isDemoCourse, remoteReload, setNotice]);

  useEffect(() => {
    if (!CAN_START_NATIVE_CHECKOUT || isDemoCourse) return undefined;
    let active = true;
    let unsubscribe: () => void = () => undefined;
    void import('../../../services/nativeStoreBilling').then(storeBilling => {
      if (!active) return;
      unsubscribe = storeBilling.subscribeNativeStoreCredits(() => {
        setRemoteReload(value => value + 1);
      });
    });
    return () => {
      active = false;
      unsubscribe();
    };
  }, [isDemoCourse]);

  return {
    experience,
    reloadRemote: () => setRemoteReload(value => value + 1),
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
    setRemoteOwned,
    setRemotePaidBalance,
    setRemotePackages,
    setRemoteSpendableBalance,
    setRemoteRewardBalance,
  };
};
