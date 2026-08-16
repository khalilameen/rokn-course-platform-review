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
        'file_size',
        'order',
    ];

    /**
     * Get the parent attachable model (module or section).
     */
    public function attachable()
    {
        return $this->morphTo();
    }

    /**
     * Get the file URL.
     */
    public function getFileUrlAttribute()
    {
        return $this->storage_disk === 'public'
            ? asset('storage/' . ltrim((string) $this->file_path, '/'))
            : null;
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
