<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\StoreResource;


class ServicesResource extends JsonResource
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
            'name_ar' => (string) $this->name_ar,
            'name_en' => (string) $this->name_en,
            'description_ar' => (string)$this->description_ar,
            'description_en' => (string)$this->description_en,
            'image' => $this->image ?: null,
            'stores' => StoreResource::collection($this->stores)
        ];
    }
}
