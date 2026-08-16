<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasPhoto;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Question extends Model
{
    use HasPhoto;
    //	id	title	question	choice1	choice2	choice3	choice4	choice5	choice6	right_answer	created_at	updated_at	list_id
    protected $photoModel = 'App\Models\Photo';
    protected $fillable = ['title',	'question','question_image',	'description', 'priority' ,'choice1',	'choice2',	'choice3',	'choice4',	'choice5',	'choice6',	'right_answer',	'created_at',	'updated_at',	'list_id'];

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

}
