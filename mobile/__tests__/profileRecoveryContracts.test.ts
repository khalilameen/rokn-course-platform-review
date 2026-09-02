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
});
