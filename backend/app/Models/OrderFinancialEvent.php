<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OrderFinancialEvent extends Model
{
    protected $fillable = [
        'order_id',
        'actor_id',
        'event_type',
        'event_key',
        'provider',
        'external_event_id',
        'recovered_coins',
        'unrecovered_coins',
        'reason',
        'payload',
        'occurred_at',
    ];

    protected $casts = [
        'recovered_coins' => 'integer',
        'unrecovered_coins' => 'integer',
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
