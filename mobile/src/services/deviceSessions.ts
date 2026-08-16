import {publicRequest} from '../constants/api';

export type DeviceSession = {
  id: string;
  platform: 'android' | 'ios' | 'web' | 'other';
  app_version?: string | null;
  app_build?: string | null;
  issued_at?: string | null;
  last_used_at?: string | null;
  expires_at?: string | null;
  current: boolean;
};

export const getDeviceSessions = async (): Promise<DeviceSession[]> => {
  const response = await publicRequest.get<{data?: unknown}>('user/sessions');
  const rows = response.data?.data;
  return Array.isArray(rows) ? (rows as DeviceSession[]) : [];
};

export const revokeDeviceSession = async (sessionId: string) => {
  await publicRequest.delete(`user/sessions/${sessionId}`);
};

export const revokeCurrentDeviceSession = async (
  deviceToken?: string | null,
) => {
  await publicRequest.post(
    'logout',
    deviceToken ? {device_token: deviceToken} : {},
  );
};
