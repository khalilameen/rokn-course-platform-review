<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\QuestionResource;
class RandomQuizResource extends JsonResource
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
            'question_time' => $this->question_time,
            'description' =>$this->description ? (string)$this->description: null,
            'image' =>$this->image ? (string)$this->image: null,            
            // Legacy random quizzes have no course relationship. Keep this as
            // an authenticated metadata preview; never sample the global paid
            // question bank into its response.
            'preview_only' => true,
            'created_at' =>  (string)$this->created_at,
            'updated_at' =>  (string)$this->updated_at,
        ];

    }
}
