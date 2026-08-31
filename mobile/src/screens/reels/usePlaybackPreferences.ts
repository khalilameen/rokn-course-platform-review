import {useCallback, useEffect, useRef, useState} from 'react';
import type {VideoQuality} from '../../components/VideoPlayer/types';
import {getItem, saveItem} from '../../constants/helpers';
import {
  getProfile,
  hasSession,
  updatePlaybackPreferences,
} from '../../services/roknApi';

export const usePlaybackPreferences = (serverSession: boolean | null) => {
  const [playbackSpeed, setPlaybackSpeed] = useState(1);
  const playbackSpeedRef = useRef(1);
  const [selectedQuality, setSelectedQuality] = useState<VideoQuality>('auto');
  const [dataSaver, setDataSaver] = useState(false);
  const [playbackPreferencesReady, setPlaybackPreferencesReady] =
    useState(false);
  playbackSpeedRef.current = playbackSpeed;

  useEffect(() => {
    void Promise.all([
      getItem('VIDEO_QUALITY'),
      getItem('VIDEO_PLAYBACK_SPEED'),
    ])
      .then(async ([savedQuality, savedSpeed]) => {
        const profile = (await hasSession())
          ? await getProfile().catch(() => null)
          : null;
        if (profile) {
          savedQuality = profile.videoQualityPreference;
          savedSpeed = profile.playbackSpeed;
          await Promise.all([
            saveItem('VIDEO_QUALITY', savedQuality),
            saveItem('VIDEO_PLAYBACK_SPEED', savedSpeed),
          ]);
        }
        setDataSaver(savedQuality === 'data_saver');
        const normalizedQuality =
          savedQuality === 'data_saver' || savedQuality === 'توفير البيانات'
            ? '360p'
            : savedQuality === 'تلقائي'
            ? 'auto'
            : savedQuality;
        if (
          ['auto', '1080p', '720p', '480p', '360p'].includes(
            String(normalizedQuality),
          )
        ) {
          setSelectedQuality(normalizedQuality as VideoQuality);
        }
        if (
          typeof savedSpeed === 'number' &&
          [0.75, 1, 1.25, 1.5, 2].includes(savedSpeed)
        ) {
          setPlaybackSpeed(savedSpeed);
        }
      })
      .finally(() => setPlaybackPreferencesReady(true));
  }, []);

  const changeQuality = useCallback(
    (quality: VideoQuality) => {
      setDataSaver(false);
      setSelectedQuality(quality);
      void saveItem('VIDEO_QUALITY', quality);
      if (serverSession) {
        void updatePlaybackPreferences({
          videoQualityPreference: quality,
        }).catch(() => undefined);
      }
    },
    [serverSession],
  );

  const changePlaybackSpeed = useCallback(
    (speed: number) => {
      setPlaybackSpeed(speed);
      void saveItem('VIDEO_PLAYBACK_SPEED', speed);
      if (serverSession) {
        void updatePlaybackPreferences({playbackSpeed: speed}).catch(
          () => undefined,
        );
      }
    },
    [serverSession],
  );

  const getPlaybackSpeed = useCallback(() => playbackSpeedRef.current, []);

  return {
    autoplay: true,
    changePlaybackSpeed,
    changeQuality,
    dataSaver,
    getPlaybackSpeed,
    playbackPreferencesReady,
    playbackSpeed,
    selectedQuality,
  };
};
