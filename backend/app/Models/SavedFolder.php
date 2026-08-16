<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SavedFolder extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
    ];

    /**
     * Get the user that owns the saved folder.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the lessons saved in this folder.
     */
    public function lessons()
    {
        return $this->belongsToMany(Lesson::class, 'saved_folder_lessons')
                    ->withTimestamps();
    }
}
