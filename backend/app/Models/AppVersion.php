<?php

namespace App\Models;

use App\Services\PublicAppSettingsService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AppVersion extends Model
{
    protected static function booted(): void
    {
        $invalidate = static function (): void {
            Cache::forget('app-release-channels:v2');
            PublicAppSettingsService::invalidate();
        };
        static::saved($invalidate);
        static::deleted($invalidate);
    }

    protected $fillable = [
        'platform',
        'distribution_channel',
        'version_name',
        'version_code',
        'build_number',
        'is_force_update',
        'is_active',
        'update_message_ar',
        'update_message_en',
        'download_url',
        'release_notes_ar',
        'release_notes_en',
    ];

    protected $casts = [
        'version_code' => 'integer',
        'build_number' => 'integer',
        'is_force_update' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePlatform($query, $platform)
    {
        return $query->where('platform', $platform);
    }
}
