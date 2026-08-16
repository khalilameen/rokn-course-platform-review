<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortfolioMedia extends Model
{
    protected $table = 'portfolio_media';

    protected $fillable = [
        'portfolio_item_id',
        'file_path',
        'file_type',
        'caption',
        'thumbnail_path',
        'width',
        'height',
        'duration_seconds',
        'sort_order',
    ];

    public function portfolioItem()
    {
        return $this->belongsTo(PortfolioItem::class);
    }
}
