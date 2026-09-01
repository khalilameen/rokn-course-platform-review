<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'title',
        'file_path',
        'storage_disk',
        'file_type',
        'mime_type',
        'file_size',
        'content_sha256',
        'order',
    ];

    /**
     * Get the parent attachable model (module or section).
     */
    public function attachable()
    {
        return $this->morphTo();
    }

    /** Course attachments never have a durable public URL. */
    public function getFileUrlAttribute(): ?string
    {
        // Access-aware resources issue a short-lived signed download URL after
        // checking the current enrollment. Keeping this accessor fail-closed
        // prevents a future serializer from exposing a legacy public-disk path.
        return null;
    }

    public function getStorageDiskAttribute($value): string
    {
        return filled($value) ? (string) $value : 'public';
    }

    /**
     * Get human-readable file size.
     */
    public function getFileSizeHumanAttribute()
    {
        $bytes = $this->file_size;
        if (!$bytes) return null;
        
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Scope to order attachments.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }
}
