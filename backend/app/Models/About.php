<?php

namespace App\Models;

use App\Traits\ResolvesLocalizedAttributes;
use Illuminate\Database\Eloquent\Model;

class About extends Model
{
    use ResolvesLocalizedAttributes;

    protected $fillable = ['about_ar', 'about_en', 'privacy_ar', 'policy_ar', 'privacy_en', 'policy_en'];

    public function getAboutAttribute()
    {
        return $this->localizedValue('about_ar', 'about_en');
    }

    public function getprivacyAttribute()
    {
        return $this->localizedValue('privacy_ar', 'privacy_en');
    }

    public function getPolicyAttribute()
    {
        return $this->localizedValue('policy_ar', 'policy_en');
    }
}
