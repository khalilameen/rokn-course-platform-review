<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasPhoto;
use App\Traits\HasTranslate;
class AdminNotification extends Model
{
	use HasTranslate,HasPhoto;
	protected $fillable = [ 'title_ar','title_en','description_ar','description_en','link', 'created_at', 'updated_at'];
}	
