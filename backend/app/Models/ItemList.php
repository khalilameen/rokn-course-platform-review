<?php

namespace App\Models;
use App\Traits\HasPhoto;
use App\Traits\ResolvesLocalizedAttributes;
use Illuminate\Database\Eloquent\Model;

class ItemList extends Model
{
    //
    use HasPhoto, ResolvesLocalizedAttributes;
    protected $table = 'lists';
    protected $fillable = [
        'title',
        'title_ar',
        'title_en',
        'category_id',
        'course_id',
        'type',
        'authoring_request_id',
        'priority',
        'description',
        'description_ar',
        'description_en',
        'image',
        'is_opened',
        'time_minutes',
        'created_at',
        'updated_at'
    ];

    /**
     * Get the title attribute based on Accept-Language header.
     */
    public function getTitleAttribute()
    {
        if (!array_key_exists('title_ar', $this->attributes) && !array_key_exists('title_en', $this->attributes)) {
            return $this->attributes['title'] ?? null;
        }

        return $this->localizedValue('title_ar', 'title_en', 'title');
    }

    /**
     * Get the description attribute based on Accept-Language header.
     */
    public function getDescriptionAttribute()
    {
        if (!array_key_exists('description_ar', $this->attributes) && !array_key_exists('description_en', $this->attributes)) {
            return $this->attributes['description'] ?? null;
        }

        return $this->localizedValue('description_ar', 'description_en', 'description');
    }
    protected $photoModel = 'App\Models\Photo';
    public function scopeQuiz($query){
        return $query->where("type",'quiz');
    }
    public function scopeStandaloneQuiz($query)
    {
        return $query->quiz()
            ->whereNull('course_id')
            ->whereDoesntHave('courseSection');
    }
    public function scopeCourse($query){
        return $query->where("type",'course');
    }

    public function sections()
    {
        return $this->hasMany(CourseSection::class)->orderBy('order');
    }

    public function courseSection()
    {
        return $this->morphOne(CourseSection::class, 'sectionable');
    }

    public function lessons(){
    	return $this->hasMany('App\Models\Lesson','list_id','id');
    }

     public function lesson(){
    	return $this->belongsTo('App\Models\Lesson','id','quiz_id');
    }
    public function questions(){
    	return $this->hasMany('App\Models\Question','list_id','id');
    }
    public function category(){
        return $this->belongsTo('App\Models\Category');
    }

    public function socialGroups(){
        return $this->hasMany(SocialGroup::class,'list_id');
    }

    public function courses(){
        return $this->hasMany(ItemList::class,'parent_id', 'id');
    }

    public function parentCourse(){
        return $this->belongsTo(ItemList::class, 'parent_id', 'id');
    }

    public function course(){
        return $this->belongsTo(Course::class);
    }

    public function examAttempts()
    {
        return $this->hasMany(ExamAttempt::class, 'quiz_id');
    }
}
