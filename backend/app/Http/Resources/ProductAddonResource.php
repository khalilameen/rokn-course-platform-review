<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\ProductAddonDetailResource;
class ProductAddonResource extends JsonResource
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
            'type' => (int)$this->type,
            'title_ar' => (string)$this->title_ar,
            'title_en' =>  (string)$this->title_en,
            'details' =>ProductAddonDetailResource::collection($this->details),
        ];
    }
}
