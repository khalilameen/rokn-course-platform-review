<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;


class AdminNotificationsResource extends JsonResource
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
            'id' => (int) $this->id,
            'title_ar' => (string) $this->title_ar,
            'title_en' => (string) $this->title_en,
            'description_ar' => (string)$this->description_ar,
            'description_en' => (string)$this->description_en,
            'link' => (string)$this->link,
            'image' => $this->image ? $this->image : url('/images/service.jpg'),
            'created_at' => (string)$this->created_at,
            'updated_at' => (string)$this->updated_at
            
        ];
    }
}
