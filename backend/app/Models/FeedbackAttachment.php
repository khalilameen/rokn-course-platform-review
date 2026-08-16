<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class FeedbackAttachment extends Model
{
    protected $guarded = ['id'];

    public function report() { return $this->belongsTo(FeedbackReport::class, 'feedback_report_id'); }
}
