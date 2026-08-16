<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UsersResource extends JsonResource
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
            'id' => (integer)$this->id,
            'name' => (string)$this->name,
            'phone' => (string)$this->phone,
            'wallet_coins' => (float)$this->wallet_coins,
            'wallet_purchased_coins' => (int) min(max(0, (int) $this->wallet_coins), max(0, (int) $this->wallet_purchased_coins)),
            'wallet_reward_coins' => (int) max(0, (int) $this->wallet_coins - min(max(0, (int) $this->wallet_coins), max(0, (int) $this->wallet_purchased_coins))),
            'image' => $this->image ? $this->image : url('/images/service.jpg'),
            'profile_image' => $this->profile_image_url,
            'job_title' => $this->job_title,
            'profile_deeplink' => $this->profile_deeplink,
        ];
    }
}
