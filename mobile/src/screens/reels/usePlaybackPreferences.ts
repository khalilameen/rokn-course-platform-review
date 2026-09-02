import {useCallback, useEffect, useRef, useState} from 'react';
import type {VideoQuality} from '../../components/VideoPlayer/types';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
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
    let active = true;
    void (async () => {
      const boundary = await captureAccountSessionBoundary();
      const qualityKey = await accountScopedStorageKey(
        'VIDEO_QUALITY',
        boundary,
      );
      const speedKey = await accountScopedStorageKey(
        'VIDEO_PLAYBACK_SPEED',
        boundary,
      );
      let [savedQuality, savedSpeed] = await Promise.all([
        getItem(qualityKey),
        getItem(speedKey),
      ]);
      assertAccountSessionBoundary(boundary);
      if (!active) return;
      const profile = (await hasSession())
        ? await getProfile().catch(() => null)
        : null;
      assertAccountSessionBoundary(boundary);
      if (!active) return;
      if (profile) {
        savedQuality = profile.videoQualityPreference;
        savedSpeed = profile.playbackSpeed;
        await Promise.all([
          saveItem(qualityKey, savedQuality),
          saveItem(speedKey, savedSpeed),
        ]);
        assertAccountSessionBoundary(boundary);
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
    })()
      .catch(() => undefined)
      .finally(() => {
        if (active) setPlaybackPreferencesReady(true);
      });
    return () => {
      active = false;
    };
  }, []);

  const changeQuality = useCallback(
    (quality: VideoQuality) => {
      setDataSaver(false);
      setSelectedQuality(quality);
      void captureAccountSessionBoundary()
        .then(async boundary => {
          await saveItem(
            await accountScopedStorageKey('VIDEO_QUALITY', boundary),
            quality,
          );
          assertAccountSessionBoundary(boundary);
          if (serverSession) {
            await updatePlaybackPreferences({
              videoQualityPreference: quality,
            });
            assertAccountSessionBoundary(boundary);
          }
        })
        .catch(() => undefined);
    },
    [serverSession],
  );

  const changePlaybackSpeed = useCallback(
    (speed: number) => {
      setPlaybackSpeed(speed);
      void captureAccountSessionBoundary()
        .then(async boundary => {
          await saveItem(
            await accountScopedStorageKey('VIDEO_PLAYBACK_SPEED', boundary),
            speed,
          );
          assertAccountSessionBoundary(boundary);
          if (serverSession) {
            await updatePlaybackPreferences({playbackSpeed: speed});
            assertAccountSessionBoundary(boundary);
          }
        })
        .catch(() => undefined);
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
