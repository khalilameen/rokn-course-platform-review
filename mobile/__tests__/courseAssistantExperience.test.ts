jest.mock('../src/constants/api', () => ({
  publicRequest: {post: jest.fn()},
}));

jest.mock('../src/services/productFeatures', () => ({
  isProductFeatureEnabled: jest.fn(),
}));

import {publicRequest} from '../src/constants/api';
import {isProductFeatureEnabled} from '../src/services/productFeatures';
import {askCourseAssistant} from '../src/components/VideoPlayer/courseLearning/assistant';
import type {CourseLearningData} from '../src/components/VideoPlayer/types';

const course = {
  id: '52',
  accessType: 'paid',
  chatAvailable: true,
  isDemo: false,
} as CourseLearningData;

describe('course assistant waiting experience', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('signals actual provider work and allows a considered response window', async () => {
    jest.mocked(isProductFeatureEnabled).mockResolvedValue(true);
    jest.mocked(publicRequest.post).mockResolvedValue({
      data: {data: {message: 'إجابة مرتبطة بالكورس'}},
    });
    const onRequestStart = jest.fn();

    await expect(
      askCourseAssistant({course, message: 'اشرح الخطوة', onRequestStart}),
    ).resolves.toEqual(expect.objectContaining({
      text: 'إجابة مرتبطة بالكورس',
      offline: false,
      turnStatus: 'completed',
    }));

    expect(onRequestStart).toHaveBeenCalledTimes(1);
    expect(publicRequest.post).toHaveBeenCalledWith(
      'courses/52/chat',
      expect.objectContaining({message: 'اشرح الخطوة'}),
      {timeout: 60_000},
    );
    expect(onRequestStart.mock.invocationCallOrder[0]).toBeLessThan(
      jest.mocked(publicRequest.post).mock.invocationCallOrder[0],
    );
  });

  it('does not trust client history as conversation context', async () => {
    jest.mocked(isProductFeatureEnabled).mockResolvedValue(true);
    jest.mocked(publicRequest.post).mockResolvedValue({
      data: {data: {message: 'متابعة مفهومة'}},
    });

    await askCourseAssistant({
      course,
      message: 'وماذا بعد؟',
    });

    expect(publicRequest.post).toHaveBeenCalledWith(
      'courses/52/chat',
      expect.not.objectContaining({history: expect.anything()}),
      {timeout: 60_000},
    );
  });

  it('does not claim the assistant is typing when the feature is unavailable', async () => {
    jest.mocked(isProductFeatureEnabled).mockResolvedValue(false);
    const onRequestStart = jest.fn();

    const result = await askCourseAssistant({
      course,
      message: 'اشرح الخطوة',
      onRequestStart,
    });

    expect(result.unavailable).toBe(true);
    expect(result.code).toBe('ai_feature_unavailable');
    expect(onRequestStart).not.toHaveBeenCalled();
    expect(publicRequest.post).not.toHaveBeenCalled();
  });
});
