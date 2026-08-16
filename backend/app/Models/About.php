<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class About extends Model
{
    protected $fillable = ['about_ar', 'about_en', 'privacy_ar', 'policy_ar', 'privacy_en', 'policy_en'];

    public function getAboutAttribute()
    {
        if (app('request')->header('locale') === 'en' || app()->getLocale() === 'en') {
            return $this->about_en;
        } else {
            return $this->about_ar;
        }
    }

    public function getprivacyAttribute()
    {
        if (app('request')->header('locale') === 'en' || app()->getLocale() === 'en') {
            return $this->privacy_en;
        } else {
            return $this->privacy_ar;
        }
    }

    public function getPolicyAttribute()
    {
        if (app('request')->header('locale') === 'en' || app()->getLocale() === 'en') {
            return $this->policy_en;
        } else {
            return $this->policy_ar;
        }
    }
}
