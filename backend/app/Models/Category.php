<?php

namespace App\Models;
use App\Traits\HasPhoto;
use App\Traits\HasTranslate;
use Illuminate\Database\Eloquent\Model;

/**
 * Legacy marketplace/list taxonomy.
 *
 * Course discovery uses Classification through classification_course. Keep
 * this model only for old ItemList/category consumers until that contract is
 * retired; it must never be used to classify Course records.
 */
class Category extends Model
{
	use HasPhoto,HasTranslate;
    //
    protected $fillable = ['name_ar', 'name_en','type', 'description_ar', 'description_en', 'authoring_request_id'];
    /** @deprecated Use itemLists(); retained for old callers only. */
    public function courses()
    {
        return $this->itemLists();
    }

    public function itemLists()
    {
        return $this->hasMany(ItemList::class);
    }
}
 
