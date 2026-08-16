<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
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
            'message' => (string)$this->message,
            'latitude' => $this->latitude ? (double)$this->latitude : null,
            'longitude' =>$this->longitude ? (double)$this->longitude: null,
            'image' => $this->image ? $this->image : null,
            'user' => new UsersResource($this->user),
            'date' => $this->created_at->format('h:i A'),
        ];
    }
}
