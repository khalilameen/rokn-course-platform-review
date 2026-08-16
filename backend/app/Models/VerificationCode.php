<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\WhatsAppService;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class VerificationCode extends Model
{
    protected $fillable = [
        'phone',
        'code',
        'type',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    /**
     * Check if the code is valid (not expired and not used)
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return !$this->used_at && $this->expires_at->isFuture();
    }

    /**
     * Mark the code as used
     *
     * @return bool
     */
    public function markAsUsed(): bool
    {
        return $this->update(['used_at' => now()]);
    }

    /**
     * Create a new verification code for a phone number
     *
     * @param string $phone
     * @param string $type
     * @return self
     */
    public static function createForPhone(string $phone, string $type = 'verification'): self
    {
        // Invalidate any existing unused codes for this phone and type
        self::where('phone', $phone)
            ->where('type', $type)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $expiryMinutes = config('whatsapp.verification.expiry_minutes', 10);
        $code = WhatsAppService::generateCode();

        return self::create([
            'phone' => $phone,
            'code' => $code,
            'type' => $type,
            'expires_at' => Carbon::now()->addMinutes($expiryMinutes),
        ]);
    }

    /**
     * Find a valid code for verification
     *
     * @param string $phone
     * @param string $code
     * @param string $type
     * @return self|null
     */
    public static function findValidCode(string $phone, string $code, string $type = 'verification'): ?self
    {
        return self::where('phone', $phone)
            ->where('code', $code)
            ->where('type', $type)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();
    }
}
