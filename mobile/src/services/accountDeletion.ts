import {publicRequest, type RoknRequestConfig} from '../constants/api';

export type AccountDeletionResult = {
  cleanupPending: boolean;
  message?: string;
};

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null;

export const deleteRemoteAccount = async (
  reauthToken: string,
): Promise<AccountDeletionResult> => {
  const requestConfig: RoknRequestConfig = {
    headers: {Authorization: `Bearer ${reauthToken}`},
    skipPersistedSessionInvalidation: true,
  };
  const response = await publicRequest.post<{data?: unknown}>(
    'delete-account',
    {},
    requestConfig,
  );
  const nested = response.data?.data;
  const body: Record<string, unknown> = isRecord(nested)
    ? nested
    : isRecord(response.data)
    ? response.data
    : {};
  return {
    cleanupPending:
      response.status === 202 || body.deletion_status === 'cleanup_pending',
    message: typeof body.message === 'string' ? body.message : undefined,
  };
};

/** Close a short-lived reauthentication session when deletion did not run. */
export const revokeReauthenticationSession = async (token: string) => {
  const sessionToken = token.trim();
  if (!sessionToken) return;
  await publicRequest.post(
    'logout',
    {},
    {
      headers: {Authorization: `Bearer ${sessionToken}`},
      skipPersistedSessionInvalidation: true,
    } as RoknRequestConfig,
  );
};
