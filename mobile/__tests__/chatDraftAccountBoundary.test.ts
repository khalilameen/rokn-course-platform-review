import AsyncStorage from '@react-native-async-storage/async-storage';
import fs from 'node:fs';
import path from 'node:path';

const mockCaptureBoundary = jest.fn(async () => ({epoch: 2, scope: 'user-b'}));
const mockAssertBoundary = jest.fn();

jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: jest.fn(
    async (key: string, boundary?: {scope: string}) =>
      `${key}:${boundary?.scope || 'user-b'}`,
  ),
  assertAccountSessionBoundary: (...args: unknown[]) =>
    mockAssertBoundary(...args),
  captureAccountSessionBoundary: () => mockCaptureBoundary(),
}));

jest.mock('../src/services/learnerDraftFiles', () => ({
  learnerDraftFileIsReadable: jest.fn(async () => true),
  retainLearnerDraftFiles: jest.fn(async () => undefined),
  removeLearnerDraftFile: jest.fn(async () => undefined),
}));

import {
  loadCourseChatHistory,
  saveCourseChatHistory,
} from '../src/components/VideoPlayer/courseChat/persistence';
import {saveProjectFeedbackDraft} from '../src/services/projectSubmissionDraft';

describe('chat draft account ownership', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
  });

  it('keeps post-upload chat persistence under the account that started it', async () => {
    const owner = {epoch: 1, scope: 'user-a'} as const;
    await saveCourseChatHistory(
      '52',
      [
        {
          id: 'user-request-1',
          role: 'user',
          text: 'راجع الملف',
          createdAt: Date.now(),
          deliveryStatus: 'sent',
          contextEligible: true,
        },
      ],
      '7',
      owner,
    );

    expect(mockCaptureBoundary).not.toHaveBeenCalled();
    expect(AsyncStorage.setItem).toHaveBeenCalledWith(
      expect.stringContaining(':user-a:'),
      expect.any(String),
    );
    expect(mockAssertBoundary).toHaveBeenCalledWith(owner);
  });

  it('keeps an accepted turn pending so reopening reconciles the same request', async () => {
    await saveCourseChatHistory('52', [
      {
        id: 'assistant-request-1',
        role: 'assistant',
        text: '',
        createdAt: Date.now(),
        pending: true,
        clientRequestId: 'request-1',
        deliveryStatus: 'queued',
        contextEligible: false,
      },
    ], '7');

    const [restored] = await loadCourseChatHistory('52', '7');
    expect(restored).toMatchObject({
      clientRequestId: 'request-1',
      deliveryStatus: 'queued',
      pending: true,
    });
  });

  it('keeps a project feedback draft under its sending account', async () => {
    const owner = {epoch: 1, scope: 'user-a'} as const;
    await saveProjectFeedbackDraft(
      '11111111-1111-4111-8111-111111111111',
      {
        text: 'راجع المشروع',
        attachments: [],
        updatedAt: Date.now(),
      },
      owner,
    );

    expect(mockCaptureBoundary).not.toHaveBeenCalled();
    expect(AsyncStorage.setItem).toHaveBeenCalledWith(
      expect.stringContaining(':user-a:'),
      expect.any(String),
    );
    expect(mockAssertBoundary).toHaveBeenCalledWith(owner);
  });

  it('threads the captured owner through both post-upload flows', () => {
    const chat = fs.readFileSync(
      path.resolve(
        __dirname,
        '../src/components/VideoPlayer/courseChat/useCourseChat.ts',
      ),
      'utf8',
    );
    const project = fs.readFileSync(
      path.resolve(
        __dirname,
        '../src/components/VideoPlayer/ProjectTransition.tsx',
      ),
      'utf8',
    );

    expect(chat).toContain('const turnBoundary = await captureAccountSessionBoundary()');
    expect(chat).toMatch(
      /saveCourseChatHistory\([\s\S]*?turnBoundary[\s\S]*?uploadCourseAssistantAttachment[\s\S]*?saveCourseChatHistory\([\s\S]*?turnBoundary/,
    );
    expect(project).toContain(
      'const feedbackBoundary = await captureAccountSessionBoundary()',
    );
    expect(project).toMatch(
      /uploadProjectFeedbackAttachment[\s\S]*?saveProjectFeedbackDraft\([\s\S]*?feedbackBoundary/,
    );
    expect(project).toContain('activeProjectIdRef.current === projectId');
    expect(project).toContain('activeFeedbackThreadIdRef.current === threadId');
    expect(project).toMatch(
      /uploadProjectFeedbackAttachment[\s\S]*?if \(!ownsFeedbackContext\(\)\) return;[\s\S]*?sendProjectFeedbackMessage/,
    );
    expect(chat).toMatch(
      /catch \(error: unknown\)[\s\S]*?ACCOUNT_CHANGED_DURING_REQUEST/,
    );
  });
});
