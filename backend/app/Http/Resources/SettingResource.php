<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SettingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'site_name' => app('request')->header('locale') === 'en' ?
                config('settings.site_name_en') : config('settings.site_name_ar'),
            'currency' => app('request')->header('locale') === 'en' ?
                config('settings.currency_code') : translateCurrency(config('settings.currency_code')) ,
            'phone' => config('settings.phone'),
            'email' => config('settings.email'),
            'whatsapp' => config('settings.whatsapp'),
            'facebook' => config('settings.facebook'),
            'instagram' => config('settings.instagram'),
            'twitter' => config('settings.twitter'),
            'seo_meta_title' => app('request')->header('locale') === 'en' ?
                config('settings.seo_meta_title_en') : config('settings.seo_meta_title_ar'),
            'seo_meta_description' => app('request')->header('locale') === 'en' ?
                config('settings.seo_meta_description_en') : config('settings.seo_meta_description_ar'),
            'max_hours' => (int) config('settings.max_hours'),
            'max_providers' => (int)config('settings.max_providers'),
        ];
    }
}
