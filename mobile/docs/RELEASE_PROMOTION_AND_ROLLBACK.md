# Release promotion, provenance, and rollback

Every production candidate is one immutable signed APK/AAB plus its adjacent
`*.json` sidecar, Hermes source map, R8 mapping, and staging smoke evidence.
Never rebuild the same version to promote it: promotion means distributing the
exact byte sequence whose SHA-256 was reviewed.

## Promotion gate

1. Build from a clean, reviewed commit with `npm run aab:play` (or the direct
   production APK command). The build script creates the provenance sidecar.
2. Verify the exact file before upload:
   `npm run verify:provenance -- artifacts/Rokn-play.aab`.
3. Run `npm run e2e:android:staging` against the candidate and archive Maestro
   output with the sidecar. Missing protected staging credentials is a failed
   gate, not a pass or a waived test.
4. Upload that same verified AAB to Play internal testing. Promote it from
   internal to closed, then production in stages (5%, 20%, 50%, 100%), with a
   24-hour observation window and rollback owner recorded at each stage.
5. Keep the previous approved artifact, its symbols, and its sidecar available
   until the new version reaches 100% plus seven days.

## Rollback

Stop the rollout first. If the fault can be safely isolated server-side, use a
reviewed operational switch and document its expiry; otherwise promote the
previous verified Play artifact or ship a higher-version hotfix. Google Play
does not permit decreasing `versionCode`, so never attempt to re-upload an old
APK with the old version code. Preserve crash/ANR identifiers, artifact SHA,
affected cohort, decision owner, and timestamp in the incident record.

The release captain must verify that the rollback artifact passes
`verify:provenance`, is signed for the same application id, and targets the
same production API before promotion. A rollback is complete only after health
metrics stabilize and the staged rollout is paused or replaced.
