<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Log;

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
        $settings = Setting::first();
        $policy = $settings->device_login_policy ?? 'multiple_devices';

        // If no device_id provided, generate one from request metadata
        if (empty($deviceId)) {
            $deviceId = $this->generateDeviceIdFromRequest();
        }

        Log::info('Device access check', [
            'user_id' => $user->id,
            'policy' => $policy,
        ]);

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
            'action' => 'allow',
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
        // Check if user is logging in from a different device
        $isDifferentDevice = $user->locked_device_id && $user->locked_device_id !== $deviceId;

        if ($isDifferentDevice) {
            // Revoke all existing API tokens (logout from all other devices)
            $user->purgeApiTokens();

            Log::info('User switched device (single_device policy)', [
                'user_id' => $user->id,
            ]);
        }

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
        Log::warning('Login attempt from unauthorized device', [
            'user_id' => $user->id,
        ]);

        return [
            'allowed' => false,
            'message' => 'لا يمكنك تسجيل الدخول من هذا الجهاز. حسابك مرتبط بجهاز آخر. '
                . 'يرجى التواصل مع المعلم لإعادة تعيين الجهاز.',
            'action' => 'deny',
            'device_id' => $deviceId,
        ];
    }

    /**
     * Generate device ID from request metadata (fallback)
     *
     * @return string
     */
    private function generateDeviceIdFromRequest(): string
    {
        $request = request();

        // Create a hash from user agent and IP address
        $userAgent = $request->userAgent();
        // $ipAddress = $request->ip();

        return hash('sha256', $userAgent);
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
                Log::info('Device locked', [
                    'user_id' => $user->id,
                ]);
                break;

            case 'logout_others':
                // Update the current device
                $user->forceFill(['locked_device_id' => $deviceId])->save();
                Log::info('Device updated (logout others)', [
                    'user_id' => $user->id,
                ]);
                break;

            case 'allow':
                // Just update the device_id for tracking (optional)
                if ($user->locked_device_id !== $deviceId) {
                    $user->forceFill(['locked_device_id' => $deviceId])->save();
                }
                break;

            case 'deny':
                // No action needed, access was denied
                break;
        }
    }
}
