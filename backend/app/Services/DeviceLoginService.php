<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DeviceLoginService
{
    /**
     * Check if user can login from the given device based on device login policy
     *
     * @param User $user
     * @param string|null $deviceId
     * @return array ['allowed' => bool, 'message' => string, 'action' => string]
     */
    public function checkDeviceAccess(User $user, ?string $deviceId): array
    {
        // Get device login policy from settings
        try {
            $settings = Setting::first();
            $policy = $settings?->device_login_policy ?? 'multiple_devices';
        } catch (Throwable $exception) {
            // Optional dashboard settings must not take authentication down
            // during a rolling migration.
            report($exception);
            $policy = 'multiple_devices';
        }

        $deviceId = trim((string) $deviceId);

        if ($policy !== 'multiple_devices' && $deviceId === '') {
            return [
                'allowed' => false,
                'message' => "حدّث التطبيق\nثم حاول تسجيل الدخول",
                'action' => 'deny',
                'device_id' => '',
            ];
        }

        switch ($policy) {
            case 'multiple_devices':
                return $this->handleMultipleDevices($user, $deviceId);

            case 'single_device':
                return $this->handleSingleDevice($user, $deviceId);

            case 'single_device_permanent':
                return $this->handleSingleDevicePermanent($user, $deviceId);

            default:
                return [
                    'allowed' => true,
                    'message' => '',
                    'action' => 'allow',
                    'device_id' => $deviceId
                ];
        }
    }

    /**
     * Handle multiple devices policy - always allow
     *
     * @param User $user
     * @param string $deviceId
     * @return array
     */
    private function handleMultipleDevices(User $user, string $deviceId): array
    {
        return [
            'allowed' => true,
            'message' => 'تم تسجيل الدخول بنجاح',
            'action' => 'allow_multiple',
            'device_id' => $deviceId
        ];
    }

    /**
     * Handle single device policy - allow but logout other devices
     *
     * @param User $user
     * @param string $deviceId
     * @return array
     */
    private function handleSingleDevice(User $user, string $deviceId): array
    {
        if (empty($user->locked_device_id)) {
            return [
                'allowed' => true,
                'message' => 'تم تسجيل الدخول بنجاح',
                'action' => 'lock_device',
                'device_id' => $deviceId,
            ];
        }

        $isDifferentDevice = $user->locked_device_id !== $deviceId;

        return [
            'allowed' => true,
            'message' => $isDifferentDevice
                ? 'تم تسجيل الدخول بنجاح. تم تسجيل الخروج من الأجهزة الأخرى.'
                : 'تم تسجيل الدخول بنجاح',
            'action' => $isDifferentDevice ? 'logout_others' : 'allow',
            'device_id' => $deviceId
        ];
    }

    /**
     * Handle single device permanent policy - lock to first device
     *
     * @param User $user
     * @param string $deviceId
     * @return array
     */
    private function handleSingleDevicePermanent(User $user, string $deviceId): array
    {
        // If no locked device, this is first login - lock to this device
        if (empty($user->locked_device_id)) {
            return [
                'allowed' => true,
                'message' => 'تم تسجيل الدخول بنجاح. تم ربط حسابك بهذا الجهاز.',
                'action' => 'lock_device',
                'device_id' => $deviceId
            ];
        }

        // Check if current device matches locked device
        if ($user->locked_device_id === $deviceId) {
            return [
                'allowed' => true,
                'message' => 'تم تسجيل الدخول بنجاح',
                'action' => 'allow',
                'device_id' => $deviceId
            ];
        }

        // Device doesn't match - deny access
        return [
            'allowed' => false,
            'message' => "الحساب مرتبط بجهاز آخر\nتواصل مع الدعم لتغييره",
            'action' => 'deny',
            'device_id' => $deviceId,
        ];
    }

    /**
     * Apply device action after successful authentication
     *
     * @param User $user
     * @param string $action
     * @param string $deviceId
     * @return void
     */
    public function applyDeviceAction(User $user, string $action, string $deviceId): void
    {
        switch ($action) {
            case 'lock_device':
                // Lock user to this device permanently
                $user->forceFill(['locked_device_id' => $deviceId])->save();
                break;

            case 'logout_others':
                $user->purgeApiTokens();
                $this->retireOtherDevicePushRegistrations($user, $deviceId);
                $user->forceFill(['locked_device_id' => $deviceId])->save();
                break;

            case 'allow':
            case 'allow_multiple':
                break;

            case 'deny':
                // No action needed, access was denied
                break;
        }
    }

    /**
     * A one-device policy applies to notification delivery as well as API
     * access. Otherwise a phone whose bearer was revoked can keep receiving
     * private account notifications until its FCM token happens to rotate.
     */
    private function retireOtherDevicePushRegistrations(User $user, string $deviceId): void
    {
        $tokens = $user->deviceTokens();
        if (!Schema::hasColumn('user_device_tokens', 'device_id')) {
            $tokens->delete();
            return;
        }

        $tokens
            ->where(function ($query) use ($deviceId): void {
                $query->whereNull('device_id')->orWhere('device_id', '<>', $deviceId);
            })
            ->delete();
    }
}
