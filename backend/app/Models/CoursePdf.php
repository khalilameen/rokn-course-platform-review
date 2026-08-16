<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class CoursePdf extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'course_id',
        'title',
        'title_en',
        'description',
        'description_en',
        'file_path',
        'storage_disk',
        'original_filename',
        'file_size',
        'order',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'file_size' => 'integer',
        'order' => 'integer',
    ];

    /**
     * Get the course that owns the PDF.
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Get the course section relationship (polymorphic)
     */
    public function courseSection()
    {
        return $this->morphOne(CourseSection::class, 'sectionable');
    }

    /**
     * Get the full storage path for the PDF file.
     */
    public function getFullPathAttribute()
    {
        return Storage::disk($this->storage_disk)->path($this->file_path);
    }

    /**
     * Rows created before storage_disk was introduced live on the local disk.
     * Keeping that fallback here makes the schema deployment backward
     * compatible while the explicit migration command moves the bytes.
     */
    public function getStorageDiskAttribute($value): string
    {
        return filled($value) ? (string) $value : 'local';
    }

    /**
     * Check if the PDF file exists.
     */
    public function fileExists()
    {
        return Storage::disk($this->storage_disk)->exists($this->file_path);
    }

    /**
     * Get file size in human-readable format.
     */
    public function getFormattedFileSizeAttribute()
    {
        $bytes = $this->file_size;
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }

    /**
     * Delete the PDF file from storage.
     */
    public function deleteFile()
    {
        if ($this->fileExists()) {
            return Storage::disk($this->storage_disk)->delete($this->file_path);
        }
        return false;
    }

    /**
     * Scope active PDFs.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope ordered PDFs.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order', 'asc');
    }
}
