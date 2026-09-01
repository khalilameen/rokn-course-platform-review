<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortfolioItem extends Model
{
    protected $fillable = [
        'user_id',
        'client_request_id',
        'request_fingerprint',
        'course_id',
        'source_project_id',
        'title',
        'description',
        'slug',
        'role',
        'tools',
        'external_url',
        'completed_at',
        'is_public',
        'is_featured',
        'sort_order',
    ];

    protected $casts = [
        'tools' => 'array',
        'completed_at' => 'date',
        'is_public' => 'boolean',
        'is_featured' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function mediaFiles()
    {
        return $this->hasMany(PortfolioMedia::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function course()
    {
        return $this->belongsTo(Course::class)->withTrashed();
    }

    public function sourceProject()
    {
        return $this->belongsTo(Project::class, 'source_project_id');
    }
}
