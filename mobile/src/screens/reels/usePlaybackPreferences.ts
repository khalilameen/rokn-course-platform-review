import {useCallback, useEffect, useRef, useState} from 'react';
import type {
  VideoFitMode,
  VideoQuality,
} from '../../components/VideoPlayer/types';
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
  const [fitMode, setFitMode] = useState<VideoFitMode>('cover');
  const [autoplay, setAutoplay] = useState(true);
  playbackSpeedRef.current = playbackSpeed;

  useEffect(() => {
    void Promise.all([
      getItem('VIDEO_QUALITY'),
      getItem('PREF_AUTOPLAY'),
      getItem('VIDEO_PLAYBACK_SPEED'),
      getItem('VIDEO_FIT_MODE'),
    ])
      .then(async ([savedQuality, savedAutoplay, savedSpeed, savedFitMode]) => {
        const profile = (await hasSession())
          ? await getProfile().catch(() => null)
          : null;
        if (profile) {
          savedQuality = profile.videoQualityPreference;
          savedAutoplay = profile.autoplayNextEnabled;
          savedSpeed = profile.playbackSpeed;
          savedFitMode = profile.videoFitMode;
          await Promise.all([
            saveItem('VIDEO_QUALITY', savedQuality),
            saveItem('PREF_AUTOPLAY', savedAutoplay),
            saveItem('VIDEO_PLAYBACK_SPEED', savedSpeed),
            saveItem('VIDEO_FIT_MODE', savedFitMode),
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
        if (typeof savedAutoplay === 'boolean') setAutoplay(savedAutoplay);
        if (
          typeof savedSpeed === 'number' &&
          [0.75, 1, 1.25, 1.5, 2].includes(savedSpeed)
        ) {
          setPlaybackSpeed(savedSpeed);
        }
        if (savedFitMode === 'cover' || savedFitMode === 'contain') {
          setFitMode(savedFitMode);
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

  const changeFitMode = useCallback(
    (mode: VideoFitMode) => {
      setFitMode(mode);
      void saveItem('VIDEO_FIT_MODE', mode);
      if (serverSession) {
        void updatePlaybackPreferences({videoFitMode: mode}).catch(
          () => undefined,
        );
      }
    },
    [serverSession],
  );
  const getPlaybackSpeed = useCallback(() => playbackSpeedRef.current, []);

  return {
    autoplay,
    changeFitMode,
    changePlaybackSpeed,
    changeQuality,
    dataSaver,
    fitMode,
    getPlaybackSpeed,
    playbackPreferencesReady,
    playbackSpeed,
    selectedQuality,
  };
};
