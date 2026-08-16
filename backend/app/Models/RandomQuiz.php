<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RandomQuiz extends Model
{
    //	id	list_id	title	description	video_link	file_link1	file_link2	created_at	updated_at	
    protected $fillable = ['title',	'question_time'	,'created_at'	,'updated_at'];
     
}

