<?php

namespace App\Models;

use App\Traits\HasPhoto;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class Course extends Model
{
    //
    use HasPhoto,HasFactory;
    protected $fillable = [
        'name_ar', 'name_en', 'description_ar', 'description_en', 'image', 'grade_id', 'teacher_id', 'store_id',
        'price', 'price_before_discount', 'currency', 'video_count', 'hours_count', 'questions_count',
        'exam_count', 'home_work_count', 'files_count', 'students_count', 'course_type','parent_id',
        'is_main_course', 'is_coming_soon', 'is_catalog_visible', 'home_sort_order',
        'catalog_badge_ar', 'catalog_badge_en', 'catalog_badge_tone',
        'search_keywords_ar', 'search_keywords_en', 'search_title_normalized', 'search_terms_normalized',
        'ai_model_type', 'chat_ai_prompt', 'temperature', 'tokens_number', 'ai_chat_enabled', 'level_id', 'awards_badge', 'badge_track', 'created_at', 'updated_at', 'path_id'
    ];
    protected $photoModel = 'App\Models\Photo';
    protected $casts = [
        'temperature' => 'float',
        'tokens_number' => 'integer',
        'is_main_course' => 'boolean',
        'is_coming_soon' => 'boolean',
        'is_catalog_visible' => 'boolean',
        'home_sort_order' => 'integer',
        'ai_chat_enabled' => 'boolean',
        'awards_badge' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Course $course): void {
            if (
                !\Illuminate\Support\Facades\Schema::hasColumn('courses', 'search_title_normalized')
                || !\Illuminate\Support\Facades\Schema::hasColumn('courses', 'search_terms_normalized')
            ) {
                return;
            }
            if (!$course->isDirty([
                'name_ar', 'name_en', 'description_ar', 'description_en',
                'search_keywords_ar', 'search_keywords_en',
            ])) {
                return;
            }

            $normalizer = app(\App\Services\ArabicSearchNormalizer::class);
            $course->search_title_normalized = $normalizer->normalize(implode(' ', array_filter([
                $course->name_ar,
                $course->name_en,
            ])));
            $course->search_terms_normalized = $normalizer->normalize(implode(' ', array_filter([
                $course->name_ar,
                $course->name_en,
                $course->description_ar,
                $course->description_en,
                $course->search_keywords_ar,
                $course->search_keywords_en,
            ])));
        });
    }

    public function scopeVisibleInCatalog($query)
    {
        return $query->where(function ($visibility) {
            $visibility->where('is_coming_soon', false)
                ->orWhere(function ($announced) {
                    $announced->where('is_coming_soon', true)
                        ->where('is_catalog_visible', true);
                });
        });
    }

    public function scopeQuiz($query){
        return $query->where("type",'quiz');
    }
    public function scopeCourse($query){
        return $query->where("type",'course');
    }

    // Computed attributes for backward compatibility
    public function getTitleAttribute() {
        // Check if we're accessing the raw attribute (to avoid infinite loop)
        if (!array_key_exists('name_ar', $this->attributes) && !array_key_exists('name_en', $this->attributes)) {
            return null;
        }

        $lang = request()->header('Accept-Language', 'ar');
        return str_starts_with($lang, 'en')
            ? ($this->attributes['name_en'] ?? $this->attributes['name_ar'] ?? null)
            : ($this->attributes['name_ar'] ?? $this->attributes['name_en'] ?? null);
    }

    public function getDescriptionAttribute() {
        // Check if we're accessing the raw attribute (to avoid infinite loop)
        if (!array_key_exists('description_ar', $this->attributes) && !array_key_exists('description_en', $this->attributes)) {
            return null;
        }

        $lang = request()->header('Accept-Language', 'ar');
        return str_starts_with($lang, 'en')
            ? ($this->attributes['description_en'] ?? $this->attributes['description_ar'] ?? null)
            : ($this->attributes['description_ar'] ?? $this->attributes['description_en'] ?? null);
    }

    public function setTitleAttribute($value) {
        $this->attributes['name_ar'] = $value;
        $this->attributes['name_en'] = $value;
    }

    public function setDescriptionAttribute($value) {
        $this->attributes['description_ar'] = $value;
        $this->attributes['description_en'] = $value;
    }

    public function sections()
    {
        return $this->hasMany(CourseSection::class)->orderBy('order');
    }

    /**
     * Get the modules for this course.
     */
    public function modules()
    {
        return $this->hasMany(CourseModule::class)->orderBy('order');
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

    public function grade(){
        return $this->belongsTo(Grade::class);
    }

    public function level(){
        return $this->belongsTo(Level::class);
    }

    public function socialGroups(){
        return $this->hasMany(SocialGroup::class,'list_id');
    }

    public function courses(){
        return $this->hasMany(Course::class,'parent_id', 'id');
    }

    public function parentCourse(){
        return $this->belongsTo(Course::class, 'parent_id', 'id');
    }

    /**
     * Get the course type name in Arabic.
     */
    public function getCourseTypeNameAttribute()
    {
        switch ($this->course_type) {
            case 'online':
                return 'أونلاين';
            default:
                return 'أونلاين';
        }
    }

    /**
     * Get the PDFs associated with this course.
     */
    public function pdfs()
    {
        return $this->hasMany(CoursePdf::class)->ordered();
    }

    /**
     * Get active PDFs for this course.
     */
    public function activePdfs()
    {
        return $this->hasMany(CoursePdf::class)->active()->ordered();
    }

    /**
     * Get the classifications for this course.
     */
    public function classifications()
    {
        return $this->belongsToMany(Classification::class, 'classification_course');
    }

    /**
     * Get the path associated with this course.
     */
    public function coursePath()
    {
        return $this->belongsTo(Path::class, 'path_id');
    }

    /**
     * Get the ratings for this course.
     */
    public function ratings()
    {
        return $this->hasMany(CourseRating::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function activeEnrollments()
    {
        return $this->hasMany(CourseEnrollment::class)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function accessPlans()
    {
        return $this->hasMany(CourseAccessPlan::class)->orderBy('sort_order');
    }

    /**
     * Get the average rating for this course.
     */
    public function getAverageRatingAttribute()
    {
        return round($this->ratings()->avg('rating'), 1) ?: 0;
    }

    /**
     * Get the total number of ratings for this course.
     */
    public function getRatingsCountAttribute()
    {
        return $this->ratings()->count();
    }
    /**
     * Get the teachers associated with this course.
     */
    public function teachers()
    {
        return $this->belongsToMany(User::class, 'course_teacher', 'course_id', 'teacher_id')
                    // Legacy instructors were administrator accounts before a
                    // dedicated teacher role existed. Keep them visible while
                    // every newly created instructor uses the least-privilege
                    // teacher role.
                    ->whereIn('users.role', ['teacher', 'admin'])
                    ->withTimestamps();
    }

    /**
     * Get the primary teacher for this course (backward compatibility).
     */
    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
