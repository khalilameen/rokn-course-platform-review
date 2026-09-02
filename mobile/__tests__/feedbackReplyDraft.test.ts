import AsyncStorage from '@react-native-async-storage/async-storage';

jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn(), post: jest.fn()},
}));

jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: async (key: string) => `${key}:test-account`,
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 0,
    scope: 'test-account',
  })),
}));

jest.mock('../src/services/learnerDraftFiles', () => ({
  learnerDraftFileIsReadable: jest.fn(async () => true),
  removeLearnerDraftFile: jest.fn(async () => undefined),
}));

import {
  loadProductFeedbackReplyDraft,
  saveProductFeedbackReplyDraft,
} from '../src/services/productFeedback';
import {learnerDraftFileIsReadable} from '../src/services/learnerDraftFiles';

describe('support reply recovery', () => {
  beforeEach(async () => {
    jest.mocked(learnerDraftFileIsReadable).mockResolvedValue(true);
    await AsyncStorage.clear();
  });

  it('keeps the same idempotency key with the unsent reply', async () => {
    const draft = {
      attachment: {
        uri: 'file:///persistent/reply.jpg',
        type: 'image/jpeg',
      },
      clientRequestId: '63efe954-8f6d-4d9e-8859-1bb02108b166',
      message: 'الرد الذي لم تصل استجابته بعد',
    };

    await saveProductFeedbackReplyDraft('01TESTCASE0000000000000000', draft);

    await expect(
      loadProductFeedbackReplyDraft('01TESTCASE0000000000000000'),
    ).resolves.toEqual(draft);
  });

  it('clears a sent reply without leaving a replayable request id', async () => {
    const publicId = '01TESTCASE0000000000000000';
    await saveProductFeedbackReplyDraft(publicId, {
      clientRequestId: '63efe954-8f6d-4d9e-8859-1bb02108b166',
      message: 'تم الإرسال',
    });

    await saveProductFeedbackReplyDraft(publicId, null);

    await expect(loadProductFeedbackReplyDraft(publicId)).resolves.toBeNull();
  });

  it('keeps an attached image while the learner is still writing the reply', async () => {
    const publicId = '01TESTCASE0000000000000000';
    const draft = {
      attachment: {uri: 'file:///persistent/reply.jpg', type: 'image/jpeg'},
      clientRequestId: '63efe954-8f6d-4d9e-8859-1bb02108b166',
      message: '',
    };

    await saveProductFeedbackReplyDraft(publicId, draft);

    await expect(loadProductFeedbackReplyDraft(publicId)).resolves.toEqual(draft);
  });

  it('does not replay one key with a changed body when its image disappeared', async () => {
    const publicId = '01TESTCASE0000000000000000';
    await saveProductFeedbackReplyDraft(publicId, {
      attachment: {uri: 'file:///missing/reply.jpg', type: 'image/jpeg'},
      clientRequestId: '63efe954-8f6d-4d9e-8859-1bb02108b166',
      message: 'رد بصورة',
    });
    jest.mocked(learnerDraftFileIsReadable).mockResolvedValue(false);

    await expect(loadProductFeedbackReplyDraft(publicId)).resolves.toEqual({
      attachment: undefined,
      clientRequestId: '',
      message: 'رد بصورة',
    });
  });
});
