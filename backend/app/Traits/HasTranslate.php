<?php

namespace App\Traits;

trait HasTranslate
{
    public function getNameAttribute()
    {
        if (app('request')->header('locale') === 'en' || app()->getLocale() === 'en') {
            return $this->name_en;
        } else {
            return $this->name_ar;
        }
    }

    public function getDescriptionAttribute()
    {
        if (app('request')->header('locale') === 'en' || app()->getLocale() === 'en') {
            return $this->description_en;
        } else {
            return $this->description_ar;
        }
    }

    public function getTitleAttribute()
    {
        if (app('request')->header('locale') === 'en' || app()->getLocale() === 'en') {
            return $this->title_en;
        } else {
            return $this->title_ar;
        }
    }

    public function getContentAttribute()
    {
        if (app('request')->header('locale') === 'en' || app()->getLocale() === 'en') {
            return $this->content_en;
        } else {
            return $this->content_ar;
        }
    }

    public function getLocationAttribute()
    {
        if (app('request')->header('locale') === 'en' || app()->getLocale() === 'en') {
            return $this->location_en;
        } else {
            return $this->location_ar;
        }
    }
}
