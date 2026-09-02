<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminNotificationRequest;
use App\Models\AdminNotification;
use App\Services\AdminAuthoringCreateIntentService;
use App\Support\BusinessClock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminNotificationsController extends Controller
{
    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function index()
    {
        $admin_notifications = AdminNotification::query()
            ->orderBy('priority')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        return view('admin.admin_notifications.index', compact('admin_notifications'));
    }


    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function create()
    {
        return view('admin.admin_notifications.create');
    }


    /**
     * @param AdminNotificationRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(
        AdminNotificationRequest $request,
        AdminAuthoringCreateIntentService $createIntents
    )
    {
        $requestId = (string) $request->validated('authoring_request_id');
        $payload = $this->payload($request);
        $admin_notification = AdminNotification::query()
            ->where('authoring_request_id', $requestId)->first();
        if ($admin_notification) {
            if (!$this->sameCreatePayload($admin_notification, $payload, $request)) {
                throw ValidationException::withMessages([
                    'authoring_request_id' => ['تغيّرت بيانات القالب\nأعد فتح النموذج ثم أرسل'],
                ]);
            }
        } else {
            $admin_notification = DB::transaction(function () use (
                $request,
                $payload,
                $requestId,
                $createIntents
            ): AdminNotification {
                $notification = AdminNotification::create(
                    $payload + ['authoring_request_id' => $requestId]
                );
                $createIntents->checkpointResource(
                    $request,
                    AdminNotification::class,
                    $notification->id
                );
                return $notification;
            }, 3);
        }
        if (!$admin_notification->wasRecentlyCreated) {
            DB::transaction(function () use ($request, $admin_notification, $createIntents): void {
                AdminNotification::query()->whereKey($admin_notification->id)->lockForUpdate()->firstOrFail();
                $createIntents->checkpointResource(
                    $request,
                    AdminNotification::class,
                    $admin_notification->id
                );
            }, 3);
        }
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $admin_notification->storeImage(
                $file,
                'admin_notifications',
                'featured',
                'admin-message-template|'.strtolower($requestId).'|'.hash_file('sha256', $file->getRealPath())
            );
        }

        DB::transaction(function () use ($request, $admin_notification, $createIntents): void {
            $locked = AdminNotification::query()
                ->whereKey($admin_notification->id)
                ->lockForUpdate()
                ->firstOrFail();
            $createIntents->completeRedirect(
                $request,
                route('admin.admin_notifications.index'),
                302,
                AdminNotification::class,
                $locked->id
            );
        }, 3);

        return redirect()->route('admin.admin_notifications.index')->with('success', 'تمت الإضافة بنجاح ');
    }

    /**
     * @param AdminNotification $admin_notification
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function edit(AdminNotification $admin_notification)
    {
        return view('admin.admin_notifications.edit', compact('admin_notification'));
    }


    /**
     * @param AdminNotificationRequest $request
     * @param AdminNotification $admin_notification
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(AdminNotificationRequest $request, AdminNotification $admin_notification)
    {
        $payload = $this->payload($request);
        if ($admin_notification->isSystemTemplate()) {
            $payload['system_key'] = $admin_notification->system_key;
        }
        $admin_notification->update($payload);
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $admin_notification->replaceImage($file, 'admin_notifications', 'featured');
        } elseif ($request->boolean('remove_image') && $admin_notification->photo) {
            $admin_notification->deleteImage();
        }

        return redirect()->route('admin.admin_notifications.index')->with('success', 'تم التعديل بنجاح');
    }


    /**
     * @param AdminNotification $admin_notification
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Exception
     */
    public function destroy(AdminNotification $admin_notification)
    {
        if ($admin_notification->isSystemTemplate()) {
            $admin_notification->update(['is_active' => false]);

            return redirect()->route('admin.admin_notifications.index')->with('success', 'تم إيقاف القالب');
        }

        if ($admin_notification->photo) {
            $admin_notification->deleteImage();
        }
        $admin_notification->delete();

        return redirect()->route('admin.admin_notifications.index')->with('success', 'تم حذف القالب');
    }

    private function payload(AdminNotificationRequest $request): array
    {
        $payload = $request->safe()->except(['image', 'remove_image', 'authoring_request_id']);
        $payload['title_en'] = trim((string) ($payload['title_en'] ?? '')) ?: $payload['title_ar'];
        $payload['description_en'] = trim((string) ($payload['description_en'] ?? '')) ?: $payload['description_ar'];
        foreach (['starts_at', 'ends_at'] as $field) {
            $payload[$field] = BusinessClock::localInputToUtc($payload[$field] ?? null);
        }

        return $payload + [
            'is_active' => $request->boolean('is_active'),
            'is_dismissible' => $request->boolean('is_dismissible'),
        ];
    }

    private function sameCreatePayload(
        AdminNotification $notification,
        array $payload,
        AdminNotificationRequest $request
    ): bool {
        foreach ([
            'system_key', 'surface', 'title_ar', 'title_en', 'description_ar', 'description_en',
            'action_label_ar', 'action_label_en', 'secondary_action_label_ar',
            'secondary_action_label_en', 'link',
        ] as $field) {
            if ((string) ($notification->{$field} ?? '') !== (string) ($payload[$field] ?? '')) {
                return false;
            }
        }
        foreach (['priority', 'cooldown_hours'] as $field) {
            if ((int) $notification->{$field} !== (int) ($payload[$field] ?? 0)) return false;
        }
        foreach (['is_active', 'is_dismissible'] as $field) {
            if ((bool) $notification->{$field} !== (bool) ($payload[$field] ?? false)) return false;
        }
        foreach (['starts_at', 'ends_at'] as $field) {
            $stored = $notification->{$field}?->getTimestamp();
            $submitted = ($payload[$field] ?? null)?->getTimestamp();
            if ($stored !== $submitted) return false;
        }

        $photo = $notification->photo()->first();
        if (!$request->hasFile('image')) return $photo === null;
        // A prior worker can die after the template row commits but before
        // its deterministic image is attached. Let the same intent finish it.
        if (!$photo) return true;

        return $this->trackedImageMatches(
            (string) $photo->path,
            $request->file('image'),
            'admin-message-template|' . strtolower((string) $notification->authoring_request_id)
        );
    }

    private function trackedImageMatches(string $path, UploadedFile $image, string $identityPrefix): bool
    {
        $storedIdentity = pathinfo($path, PATHINFO_FILENAME);
        $contentHash = hash_file('sha256', $image->getRealPath());

        return $storedIdentity !== '' && hash_equals(
            $storedIdentity,
            hash('sha256', $identityPrefix . '|' . $contentHash)
        );
    }
}
