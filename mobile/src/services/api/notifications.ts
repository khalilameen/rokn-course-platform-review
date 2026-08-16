import {publicRequest} from '../../constants/api';
import {mapNotification} from '../notificationMapper';
import type {Notification} from '../notificationMapper';
import {payload, resourceList} from './common';

export type {Notification, ProductionNotification} from '../notificationMapper';

export type NotificationsPage = {
  notifications: Notification[];
  page: number;
  hasMore: boolean;
};

type NotificationDto = Parameters<typeof mapNotification>[0] & {
  id?: unknown;
};

type NotificationsPayloadDto = {
  data?: NotificationDto[];
  pagination?: PaginationDto;
};

type PaginationDto = {
  current_page?: unknown;
  last_page?: unknown;
  has_more_pages?: unknown;
};

type NotificationResponseDto = {
  data?: NotificationsPayloadDto & {pagination?: PaginationDto};
};

export const getNotificationsPage = async ({
  page = 1,
  perPage = 30,
}: {
  page?: number;
  perPage?: number;
} = {}): Promise<NotificationsPage> => {
  const response = await publicRequest.get('notifications', {
    params: {
      page: Math.max(1, Math.floor(page)),
      per_page: Math.max(1, Math.min(50, Math.floor(perPage))),
    },
  });
  const data = payload<NotificationsPayloadDto | NotificationDto[]>(response);
  const items = resourceList<NotificationDto>(data);
  const responseDto = response as NotificationResponseDto;
  const pagination =
    responseDto.data?.pagination ||
    (!Array.isArray(data) ? data.pagination : undefined) ||
    {};
  const currentPage = Math.max(1, Number(pagination.current_page || page) || 1);
  const notifications = items
    .filter(item => item.id !== null && item.id !== undefined)
    .map(item => mapNotification(item));
  return {
    notifications,
    page: currentPage,
    hasMore: Boolean(
      pagination.has_more_pages ??
        Number(pagination.last_page || currentPage) > currentPage,
    ),
  };
};

export const getNotifications = async (): Promise<Notification[]> =>
  (await getNotificationsPage()).notifications;

export const markNotificationRead = (id: string) =>
  publicRequest.post(`notifications/${id}/mark-read`);

export const markAllNotificationsRead = () =>
  publicRequest.post('notifications/mark-all-read');

export type ProductionNotificationsPage = NotificationsPage;
export const getProductionNotificationsPage = getNotificationsPage;
export const getProductionNotifications = getNotifications;
export const markProductionNotificationRead = markNotificationRead;
export const markAllProductionNotificationsRead = markAllNotificationsRead;
