jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 7,
    scope: 'user-account-a',
  })),
  assertAccountSessionBoundary: jest.fn(),
}));

jest.mock('../src/services/api/profile', () => ({
  appendPortfolioMedia: jest.fn(),
}));

jest.mock('../src/services/portfolioMediaOutbox', () => ({
  completePortfolioMediaUpload: jest.fn(async () => undefined),
  discardPortfolioMediaUploads: jest.fn(async () => undefined),
  listPortfolioMediaUploads: jest.fn(),
}));

import {appendPortfolioMedia} from '../src/services/api/profile';
import {
  completePortfolioMediaUpload,
  listPortfolioMediaUploads,
} from '../src/services/portfolioMediaOutbox';
import {
  replayPendingPortfolioMediaUploads,
  resetPortfolioMediaReplayForTests,
} from '../src/services/portfolioMediaReplay';

const entry = (projectId: string, clientRequestId: string) => ({
  projectId,
  clientRequestId,
  file: {uri: `file:///${clientRequestId}.jpg`},
  createdAt: 1,
  storageKey: '@test/portfolio-outbox:user-account-a',
});

describe('portfolio media replay', () => {
  beforeEach(() => {
    resetPortfolioMediaReplayForTests();
    jest.clearAllMocks();
  });

  it('coalesces startup, foreground and screen replay for one account', async () => {
    const pending = entry('42', '11111111-1111-4111-8111-111111111111');
    (listPortfolioMediaUploads as jest.Mock).mockResolvedValue([pending]);
    let finishUpload: ((value: {id: string}) => void) | undefined;
    (appendPortfolioMedia as jest.Mock).mockImplementation(
      () =>
        new Promise(resolve => {
          finishUpload = resolve;
        }),
    );

    const startup = replayPendingPortfolioMediaUploads();
    const foreground = replayPendingPortfolioMediaUploads();
    for (let turn = 0; turn < 4; turn += 1) await Promise.resolve();

    expect(listPortfolioMediaUploads).toHaveBeenCalledTimes(1);
    expect(appendPortfolioMedia).toHaveBeenCalledTimes(1);
    finishUpload?.({id: 'media-1'});
    await expect(Promise.all([startup, foreground])).resolves.toEqual([
      {
        attempted: 1,
        completed: 1,
        completedProjectIds: ['42'],
        completionRevision: 1,
      },
      {
        attempted: 1,
        completed: 1,
        completedProjectIds: ['42'],
        completionRevision: 1,
      },
    ]);
    expect(completePortfolioMediaUpload).toHaveBeenCalledTimes(1);
  });

  it('does not let one failed project block another or retry its siblings', async () => {
    const first = entry('42', '11111111-1111-4111-8111-111111111111');
    const sameProject = entry('42', '22222222-2222-4222-8222-222222222222');
    const otherProject = entry('51', '33333333-3333-4333-8333-333333333333');
    (listPortfolioMediaUploads as jest.Mock).mockResolvedValue([
      first,
      sameProject,
      otherProject,
    ]);
    (appendPortfolioMedia as jest.Mock)
      .mockRejectedValueOnce(new Error('OFFLINE'))
      .mockResolvedValueOnce({id: 'media-2'});

    await expect(replayPendingPortfolioMediaUploads()).resolves.toEqual({
      attempted: 2,
      completed: 1,
      completedProjectIds: ['51'],
      completionRevision: 1,
    });
    expect(appendPortfolioMedia).toHaveBeenNthCalledWith(
      1,
      first.projectId,
      first.file,
      first.clientRequestId,
    );
    expect(appendPortfolioMedia).toHaveBeenNthCalledWith(
      2,
      otherProject.projectId,
      otherProject.file,
      otherProject.clientRequestId,
    );
    expect(completePortfolioMediaUpload).toHaveBeenCalledTimes(1);
  });

  it('exposes a missed completion revision to a later screen caller', async () => {
    const pending = entry('42', '11111111-1111-4111-8111-111111111111');
    (listPortfolioMediaUploads as jest.Mock)
      .mockResolvedValueOnce([pending])
      .mockResolvedValueOnce([]);
    (appendPortfolioMedia as jest.Mock).mockResolvedValue({id: 'media-1'});

    await expect(replayPendingPortfolioMediaUploads()).resolves.toMatchObject({
      completed: 1,
      completionRevision: 1,
    });
    await expect(replayPendingPortfolioMediaUploads()).resolves.toEqual({
      attempted: 0,
      completed: 0,
      completedProjectIds: [],
      completionRevision: 1,
    });
  });
});
