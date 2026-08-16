<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\DeliveryTimeResource;
use App\Http\Resources\ProductResource;
class OrderAddonsResource extends JsonResource
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
            'id' =>(int) $this->id,
            'quantity' =>  $this->quantity  ,
            'addon' =>  new ProductAddonDetailResource($this->addonDetails) ,
        ];

    }
}