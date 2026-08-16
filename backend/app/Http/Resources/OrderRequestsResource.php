<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use function GuzzleHttp\Psr7\str;

class OrderRequestsResource extends JsonResource
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
            'price' => (double)$this->price,
            'distance' => (double)$this->distance,
            'rate'=> $this->provider->rate,
            'status' => $this->status->name,
            'provider' => new UsersResource($this->provider),
            'order' => new OrdersResource($this->order)
        ];
    }
}
