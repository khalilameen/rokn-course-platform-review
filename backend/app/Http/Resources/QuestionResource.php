<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\ProductResource;

class QuestionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $questionArray = [
            'id' => (int)$this->id,
            'title' => (string)$this->title,
            'question' =>  (string)$this->question,
            'description' =>$this->description ? (string)$this->description: null,
            'choice1' =>$this->choice1 ? (string)$this->choice1: null,
            'choice2' =>$this->choice2 ? (string)$this->choice2: null,       
            'question_image' =>$this->image ? (string)$this->image: null,   
            'is_image' =>$this->image ? true : false,
            'created_at' =>  (string)$this->created_at,
            'updated_at' =>  (string)$this->updated_at,
        ];
        if($this->choice3){
           $questionArray['choice3'] =  $this->choice3;    
        }
        if($this->choice4){
           $questionArray['choice4'] =  $this->choice4;    
        }
        if($this->choice5){
           $questionArray['choice5'] =  $this->choice5;    
        }  
        if($this->choice6){
           $questionArray['choice6'] =  $this->choice6;    
        }    
                            
    //  id  title   question    choice1 choice2 choice3 choice4 choice5 choice6 right_answer    created_at  updated_at  list_id
        return $questionArray;
    }
}
