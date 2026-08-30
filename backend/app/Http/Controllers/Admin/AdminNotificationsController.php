<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminNotificationRequest;
use App\Models\AdminNotification;

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
    public function store(AdminNotificationRequest $request)
    {
        $admin_notification = AdminNotification::create($this->payload($request));
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $admin_notification->storeImage($file, 'admin_notifications', 'featured');
        }

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
        $admin_notification->update($this->payload($request));
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $admin_notification->replaceImage($file, 'admin_notifications', 'featured');
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
        $admin_notification->delete();

        return redirect()->route('admin.admin_notifications.index')->with('success', 'تم الحذف بنجاح ');
    }

    private function payload(AdminNotificationRequest $request): array
    {
        return $request->safe()->except('image') + [
            'is_active' => $request->boolean('is_active'),
            'is_dismissible' => $request->boolean('is_dismissible'),
        ];
    }
}
