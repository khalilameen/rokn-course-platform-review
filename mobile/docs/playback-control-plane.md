# Rokn playback control plane

This layer keeps Bunny as the media provider and makes Rokn the authority that
decides whether, how, and for whom a lesson plays.

## Runtime contract

1. An authenticated learner opens an accessible lesson.
2. `POST /api/v1/lessons/{lesson}/playback-manifest` checks the existing course
   and module entitlement policy.
3. The backend returns a short-lived signed HLS URL, only qualities known to
   exist, media readiness, and a UUID playback session.
4. The app keeps that session in memory and attaches an increasing `sequence`
   to watch-history heartbeats.
5. The backend ignores duplicate or out-of-order samples before they reach
   learning evidence or rewards. Resume position is monotonic across devices.

The existing course video URL remains a temporary compatibility fallback if
the manifest control plane is unreachable. It must not be treated as the
source of truth by new clients.

## Media lifecycle

`lesson_media_states.status` is one of:

- `unknown`: legacy media has not been probed yet.
- `processing`: upload exists but usable renditions are not confirmed.
- `ready`: Bunny reports a playable rendition.
- `failed`: source is missing or the provider reports a failed encode.

New and replaced uploads enter `processing`. Media Health can probe an item
without publishing or changing the lesson pointer. Explicit course publishing
is blocked when a known media state is not `ready`; already-published legacy
courses are not silently unpublished.

## Compatibility and rollback

- No payment, wallet, grant, chat, certificate, project, or portfolio schema is
  changed.
- Legacy clients may continue sending watch history without a session; they
  retain the previous server-qualified evidence rules.
- Removing the manifest request from the app restores the former playback path.
- The migration rollback drops only playback sessions and media-health state;
  lesson and progress records remain intact.
- P2P distribution, federation, server-side transcoding, downloads, and
  subtitles are intentionally outside this layer.

## Deployment order

1. Deploy backend code.
2. Run the new migration.
3. Probe the media items shown in Product Operations and resolve failures.
4. Publish the mobile build.
5. Watch `media_attention`, playback sessions, provider errors, and duplicate
   sequence rates during rollout.
