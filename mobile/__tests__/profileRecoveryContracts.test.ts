import fs from 'fs';
import path from 'path';

const source = (relativePath: string) =>
  fs.readFileSync(path.resolve(__dirname, '..', relativePath), 'utf8');

describe('profile recovery contracts', () => {
  it('does not turn a saved-folder request failure into an authoritative empty list', () => {
    const savedVideos = source('src/screens/Profile/SavedVideos.tsx');

    expect(savedVideos).not.toContain('getSavedFolderOptions().catch(() => [])');
    expect(savedVideos).toContain('if (folderOptionsResult.ok)');
    expect(savedVideos).toContain('setFolderLoadError(');
  });

  it('keeps a pending certificate recoverable instead of presenting an empty account', () => {
    const certificates = source('src/screens/Profile/Certificates.tsx');

    expect(certificates).toContain('setCertificatePending(hasPendingCertificate)');
    expect(certificates).toContain('certificatePending &&');
    expect(certificates).toContain('!readyCourses.length &&');
    expect(certificates).toContain('!grantCourses.length ?');
    expect(certificates).toContain('onAction={loadCertificates}');
  });

  it('hydrates portfolio data independently and replays media as one flight', () => {
    const gallery = source('src/screens/Profile/Gallery.tsx');
    const initializer = source('src/screens/AppInitializer.tsx');

    expect(gallery).not.toContain(
      'for (const entry of await listPortfolioMediaUploads())',
    );
    expect(gallery).toContain('portfolioReplayRefreshFlightRef.current');
    expect(gallery).toContain('await replayPendingPortfolioMediaUploads()');
    expect(initializer).toContain('replayPendingPortfolioMediaUploads()');
  });

  it('keeps the share action available when one profile request is stale', () => {
    const profile = source('src/screens/Profile/index.tsx');

    expect(profile).toContain('visibleRemoteProfile?.portfolioSlug');
    expect(profile).toContain(
      "trustedPortfolioShareUrl(username ? portfolioUrlFor(username) : '')",
    );
  });

  it('owns edit, finalize and media-delete mutations synchronously', () => {
    const gallery = source('src/screens/Profile/Gallery.tsx');

    expect(gallery).toContain('projectMutationFlightRef.current = flight');
    expect(gallery).not.toContain('mediaFlightRef');
    expect(gallery).not.toContain('deleteFlightRef');
    expect(gallery.match(/beginProjectMutation\((?:false)?\)/g)).toHaveLength(5);
    expect(gallery.match(/finishProjectMutation\(flight\)/g)).toHaveLength(8);
    expect(gallery).toContain(
      '{cancelable: true, onDismiss: releaseUnstartedDelete}',
    );
    expect(gallery).toContain(
      'if (!deleteStarted) finishProjectMutation(flight)',
    );
  });
});
