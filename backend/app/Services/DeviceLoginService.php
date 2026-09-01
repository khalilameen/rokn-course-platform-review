<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DeviceLoginService
{
    public const POLICY_MULTIPLE = 'multiple_devices';
    public const POLICY_SINGLE = 'single_device';
    public const POLICY_SINGLE_PERMANENT = 'single_device_permanent';

    /**
     * A missing/corrupt setting must not silently remove the device boundary.
     * The rotating single-device policy contains concurrent variable-cost use
     * while still letting a legitimate learner move to a replacement phone.
     */
    public const SAFE_FALLBACK_POLICY = self::POLICY_SINGLE;

    /**
     * Check if user can login from the given device based on device login policy
     *
     * @param User $user
     * @param string|null $deviceId
     * @return array ['allowed' => bool, 'message' => string, 'action' => string]
     */
    public function checkDeviceAccess(User $user, ?string $deviceId): array
    {
        $policy = $this->configuredPolicy();
        $deviceId = trim((string) $deviceId);

        if (
            $policy !== self::POLICY_MULTIPLE
            && !$this->deviceLockStorageAvailable()
        ) {
            return [
                'allowed' => false,
                'message' => "تعذّر التحقق من الجهاز\nحاول مرة أخرى بعد قليل",
                'action' => 'deny',
                'device_id' => $deviceId,
            ];
        }

        if ($policy !== self::POLICY_MULTIPLE && $deviceId === '') {
            return [
                'allowed' => false,
                'message' => "حدّث التطبيق\nثم حاول تسجيل الدخول",
                'action' => 'deny',
                'device_id' => '',
            ];
        }

        switch ($policy) {
            case self::POLICY_MULTIPLE:
                return $this->handleMultipleDevices($user, $deviceId);

            case self::POLICY_SINGLE:
                return $this->handleSingleDevice($user, $deviceId);

            case self::POLICY_SINGLE_PERMANENT:
                return $this->handleSingleDevicePermanent($user, $deviceId);
        }

        // normalizePolicy() is exhaustive. Keep this closed if a future edit
        // adds a value without adding its enforcement branch here.
        return [
            'allowed' => false,
            'message' => "تعذّر التحقق من الجهاز\nحاول مرة أخرى",
            'action' => 'deny',
            'device_id' => $deviceId,
        ];
    }

    public function configuredPolicy(): string
    {
        try {
            $rawPolicy = Setting::query()->value('device_login_policy');
        } catch (Throwable $exception) {
            report($exception);
            return self::SAFE_FALLBACK_POLICY;
        }

        return self::normalizePolicy($rawPolicy);
    }

    public static function normalizePolicy(mixed $policy): string
    {
        $normalized = strtolower(trim((string) $policy));

        return in_array($normalized, [
            self::POLICY_MULTIPLE,
            self::POLICY_SINGLE,
            self::POLICY_SINGLE_PERMANENT,
        ], true)
            ? $normalized
            : self::SAFE_FALLBACK_POLICY;
    }

    private function deviceLockStorageAvailable(): bool
    {
        try {
            return Schema::hasColumn('users', 'locked_device_id');
        } catch (Throwable $exception) {
            report($exception);

            return false;
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
                // A null lock can be left over from an earlier multiple-device
                // policy. Retire those bearers before issuing the new one.
                'action' => 'logout_others',
                'device_id' => $deviceId,
            ];
        }

        $isDifferentDevice = $user->locked_device_id !== $deviceId;

        return [
            'allowed' => true,
            'message' => $isDifferentDevice
                ? 'تم تسجيل الدخول بنجاح. تم تسجيل الخروج من الأجهزة الأخرى.'
                : 'تم تسجيل الدخول بنجاح',
            // Reissuing a bearer on the same phone must still retire any
            // historical bearer that predates the device lock.
            'action' => 'logout_others',
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
                'action' => 'logout_others',
                'device_id' => $deviceId
            ];
        }

        // Check if current device matches locked device
        if ($user->locked_device_id === $deviceId) {
            return [
                'allowed' => true,
                'message' => 'تم تسجيل الدخول بنجاح',
                'action' => 'logout_others',
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
