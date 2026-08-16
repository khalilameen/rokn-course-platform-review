import {asRecord} from '../courseLearning/shared';

export const courseChatErrorCode = (error: unknown): string => {
  const failure = asRecord(error);
  const response = asRecord(failure.response);
  return String(
    asRecord(failure.data).code || asRecord(response.data).code || '',
  );
};
