<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
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
            'message' => app('request')->header('locale') === 'en' ?
                (string)$this->message_en : (string)$this->message_ar,
            'order' => $this->order ? new OrdersResource($this->order) : null,
            'created_at' => (string)$this->created_at,
            'updated_at' => (string)$this->updated_at

        ];
    }
}
