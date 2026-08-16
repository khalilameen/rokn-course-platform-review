<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Governorate extends Model
{
    use HasFactory;

    // Define fillable properties
    protected $fillable = ['name', 'country_id'];

    // Relationship with Country model
    public function country()
    {
        return $this->belongsTo(Country::class);
    }
}
