<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class FeedbackReport extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'context' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function user() { return $this->belongsTo(User::class); }
    public function course() { return $this->belongsTo(Course::class); }
    public function lesson() { return $this->belongsTo(Lesson::class); }
    public function assignee() { return $this->belongsTo(User::class, 'assigned_to'); }
    public function attachments() { return $this->hasMany(FeedbackAttachment::class); }
}
