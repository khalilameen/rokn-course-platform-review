<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasPhoto;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Question extends Model
{
    use HasPhoto;
    protected $touches = ['itemList'];
    //	id	title	question	choice1	choice2	choice3	choice4	choice5	choice6	right_answer	created_at	updated_at	list_id
    protected $photoModel = 'App\Models\Photo';
    protected $fillable = ['title',	'question','question_image',	'description', 'priority' ,'choice1',	'choice2',	'choice3',	'choice4',	'choice5',	'choice6',	'right_answer',	'created_at',	'updated_at',	'list_id', 'authoring_request_id'];

    public function course(){
         return $this->belongsTo('App\Models\Course','list_id','id');
     }

    public function itemList(){
         return $this->belongsTo('App\Models\ItemList','list_id','id');
     }

    public function courseSection()
    {
        return $this->morphOne(CourseSection::class, 'sectionable');
    }

    /** Keep legacy scalar images readable while new authoring uses Photo. */
    public function getImageAttribute(): ?string
    {
        if ($this->photo) {
            return asset('storage/' . ltrim((string) $this->photo->path, '/'));
        }

        $legacy = trim((string) ($this->attributes['question_image'] ?? ''));
        if ($legacy === '') {
            return null;
        }

        return filter_var($legacy, FILTER_VALIDATE_URL)
            ? $legacy
            : asset(ltrim($legacy, '/'));
    }

}
