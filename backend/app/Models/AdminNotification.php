<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasPhoto;
use App\Traits\HasTranslate;

class AdminNotification extends Model
{
    use HasTranslate, HasPhoto;

    public const SURFACES = [
        'guest_prompt' => 'قبل تسجيل الدخول',
        'transactional' => 'بعد إجراء مؤكد',
        'retention' => 'الاحتفاظ والعودة',
        'announcement' => 'جديد ومقترحات',
    ];

    protected $fillable = [
        'system_key',
        'surface',
        'title_ar',
        'title_en',
        'description_ar',
        'description_en',
        'action_label_ar',
        'action_label_en',
        'secondary_action_label_ar',
        'secondary_action_label_en',
        'link',
        'is_active',
        'is_dismissible',
        'priority',
        'cooldown_hours',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_dismissible' => 'boolean',
        'priority' => 'integer',
        'cooldown_hours' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function scopeAvailable($query)
    {
        return $query->where('is_active', true)
            ->where(function ($active): void {
                $active->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($active): void {
                $active->whereNull('ends_at')->orWhere('ends_at', '>', now());
            });
    }
}
