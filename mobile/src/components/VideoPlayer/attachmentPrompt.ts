import AsyncStorage from '@react-native-async-storage/async-storage';
import {accountScopedStorageKey} from '../../constants/helpers';

const ATTACHMENT_PROMPT_SEEN = 'course-attachment-prompt-seen:v1';

const seenKey = async (courseId: string, moduleId: string) =>
  `${await accountScopedStorageKey(ATTACHMENT_PROMPT_SEEN)}:${encodeURIComponent(
    courseId,
  )}:${encodeURIComponent(moduleId)}`;

export const hasSeenAttachmentPrompt = async (
  courseId: string,
  moduleId: string,
) => (await AsyncStorage.getItem(await seenKey(courseId, moduleId))) === '1';

export const markAttachmentPromptSeen = async (
  courseId: string,
  moduleId: string,
) => AsyncStorage.setItem(await seenKey(courseId, moduleId), '1');
