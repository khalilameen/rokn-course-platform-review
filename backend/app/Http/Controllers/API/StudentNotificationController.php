<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentNotificationResource;
use App\Models\Lesson;
use App\Models\StudentNotification;
use App\Models\User;
use App\Services\ApiResponseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StudentNotificationController extends Controller
{
    public function __construct(private readonly ApiResponseService $responses)
    {
    }

    public function getUnreadCount(Request $request): JsonResponse
    {
        try {
            /** @var User|null $user */
            $user = auth('api')->user();
            if (!$user) {
                return $this->responses->error('Unauthorized', 401);
            }

            $unreadCount = StudentNotification::where('user_id', $user->id)
                ->unread()
                ->count();
            $data = ['unread_count' => $unreadCount];

            return $this->responses->success(
                $data,
                'Unread notification count retrieved successfully',
                200,
                $data
            );
        } catch (\Exception $exception) {
            report($exception);

            return $this->responses->error('Failed to get unread count', 500);
        }
    }

    public function getLastTen(Request $request): JsonResponse
    {
        try {
            /** @var User|null $user */
            $user = auth('api')->user();
            if (!$user) {
                return $this->responses->error('Unauthorized', 401);
            }

            $notifications = StudentNotification::where('user_id', $user->id)
                ->with('notifiable')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get();
            $notifications->loadMorph('notifiable', [Lesson::class => ['course']]);

            return $this->responses->success(
                StudentNotificationResource::collection($notifications),
                'Notifications retrieved successfully'
            );
        } catch (\Exception $exception) {
            report($exception);

            return $this->responses->error('Failed to get notifications', 500);
        }
    }

    public function getAll(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:50',
            'filter' => 'nullable|in:read,unread',
        ]);

        try {
            /** @var User|null $user */
            $user = auth('api')->user();
            if (!$user) {
                return $this->responses->error('Unauthorized', 401);
            }

            $query = StudentNotification::where('user_id', $user->id)
                ->with('notifiable')
                ->orderByDesc('created_at');
            $filter = $validated['filter'] ?? null;
            if ($filter === 'read') {
                $query->read();
            } elseif ($filter === 'unread') {
                $query->unread();
            }

            $notifications = $query->paginate((int) ($validated['per_page'] ?? 10));
            $notifications->getCollection()->loadMorph('notifiable', [
                Lesson::class => ['course'],
            ]);
            $pagination = [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'has_more_pages' => $notifications->hasMorePages(),
            ];

            return $this->responses->success(
                StudentNotificationResource::collection($notifications),
                'Notifications retrieved successfully',
                200,
                ['pagination' => $pagination]
            );
        } catch (\Exception $exception) {
            report($exception);

            return $this->responses->error('Failed to get notifications', 500);
        }
    }

    public function markAsRead(Request $request, int|string $id): JsonResponse
    {
        try {
            /** @var User|null $user */
            $user = auth('api')->user();
            if (!$user) {
                return $this->responses->error('Unauthorized', 401);
            }

            $notification = StudentNotification::where('id', $id)
                ->where('user_id', $user->id)
                ->first();
            if (!$notification) {
                return $this->responses->error('Notification not found', 404);
            }

            $notification->markAsRead();
            $notification->loadMissing('notifiable');

            return $this->responses->success(
                new StudentNotificationResource($notification),
                'Notification marked as read'
            );
        } catch (\Exception $exception) {
            report($exception);

            return $this->responses->error('Failed to mark notification as read', 500);
        }
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        try {
            /** @var User|null $user */
            $user = auth('api')->user();
            if (!$user) {
                return $this->responses->error('Unauthorized', 401);
            }

            $updatedCount = StudentNotification::where('user_id', $user->id)
                ->unread()
                ->update([
                    'is_read' => true,
                    'read_at' => now(),
                ]);
            $data = ['updated_count' => $updatedCount];

            return $this->responses->success(
                $data,
                'All notifications marked as read',
                200,
                $data
            );
        } catch (\Exception $exception) {
            report($exception);

            return $this->responses->error('Failed to mark all notifications as read', 500);
        }
    }
}
