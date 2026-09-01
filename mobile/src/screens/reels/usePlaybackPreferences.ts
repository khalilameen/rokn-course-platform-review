import {useCallback, useEffect, useRef, useState} from 'react';
import type {VideoQuality} from '../../components/VideoPlayer/types';
import {
  accountScopedStorageKey,
  getItem,
  saveItem,
} from '../../constants/helpers';
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
    const qualityKey = accountScopedStorageKey('VIDEO_QUALITY');
    const speedKey = accountScopedStorageKey('VIDEO_PLAYBACK_SPEED');
    void Promise.all([
      qualityKey.then(getItem),
      speedKey.then(getItem),
    ])
      .then(async ([savedQuality, savedSpeed]) => {
        const profile = (await hasSession())
          ? await getProfile().catch(() => null)
          : null;
        if (profile) {
          savedQuality = profile.videoQualityPreference;
          savedSpeed = profile.playbackSpeed;
          await Promise.all([
            qualityKey.then(key => saveItem(key, savedQuality)),
            speedKey.then(key => saveItem(key, savedSpeed)),
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
        const normalizedSpeed = Number(savedSpeed);
        if ([0.75, 1, 1.25, 1.5, 2].includes(normalizedSpeed)) {
          setPlaybackSpeed(normalizedSpeed);
        }
      })
      .finally(() => setPlaybackPreferencesReady(true));
  }, []);

  const changeQuality = useCallback(
    (quality: VideoQuality) => {
      setDataSaver(false);
      setSelectedQuality(quality);
      void accountScopedStorageKey('VIDEO_QUALITY').then(key =>
        saveItem(key, quality),
      );
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
      void accountScopedStorageKey('VIDEO_PLAYBACK_SPEED').then(key =>
        saveItem(key, speed),
      );
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
