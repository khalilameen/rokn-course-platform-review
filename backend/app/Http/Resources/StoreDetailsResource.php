<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\ListResource;
class StoreDetailsResource extends JsonResource
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
            'description_ar' =>$this->description_ar ? (string)$this->description_ar: null,
            'description_en' =>$this->description_en ? (string)$this->description_en: null,
            'category_id' =>$this->category_id ? (string)$this->category_id: null,
            'lat' => $this->lat ? (string)$this->lat: null,
            'lng' =>$this->lng ? (string)$this->lng: null,
            'image' => $this->image ? $this->image : url('/images/store.png'),
            'date' => $this->created_at->format('h:i A'),
            'list'=> ListResource::collection($this->lists),
            'branches'=> BranchResource::collection($this->branches),
            'has_discount' =>$this->has_discount ,
            'discount_percent' =>$this->discount_percent ,
            'discount_limit' =>$this->discount_limit ,
        ];
    }
}
