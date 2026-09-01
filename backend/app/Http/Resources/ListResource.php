<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\ProductResource;
class ListResource extends JsonResource
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
            'id' => (int)$this->id,
            'name_ar' => (string)$this->name_ar,
            'name_en' =>  (string)$this->name_en,
            'is_opened' => (bool) $this->is_opened ?? true,
            'description_ar' =>$this->description_ar ? (string)$this->description_ar: null,
            'description_en' =>$this->description_en ? (string)$this->description_en: null,
            'image' => $this->image ?: null,
            'date' => $this->created_at ?$this->created_at->format('h:i A'):null,
            'products'=>ProductResource::collection($this->products),
        ];
    }
}
