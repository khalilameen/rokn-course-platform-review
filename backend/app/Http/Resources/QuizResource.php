<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\QuestionResource;
class QuizResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *'title', 'type','description','image', 'created_at','updated_at'
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {// id  list_id title   description video_link  file_link1  file_link2  created_at  updated_at
        return [
            'id' => (int)$this->id,
            'title' => (string)$this->title,
            'type' =>  (string)$this->type,
            'description' =>$this->description ? (string)$this->description: null,
            'image' =>$this->image ? (string)$this->image: null,
            'time_minutes' => $this->time_minutes ? (int)$this->time_minutes : null,
            'items'=> QuestionResource::collection($this->questions),
            'created_at' =>  (string)$this->created_at,
            'updated_at' =>  (string)$this->updated_at,
        ];

    }
}
