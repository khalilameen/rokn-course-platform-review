<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class WalletCreditLot extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_FROZEN = 'frozen';
    public const STATUS_REVERSED = 'reversed';
    public const STATUS_REPAID = 'repaid';
    public const STATUS_WAIVED = 'waived';
    public const STATUS_LEGACY_REVIEW = 'legacy_review';

    protected $fillable = [
        'public_id',
        'user_id',
        'source_order_id',
        'credit_transaction_id',
        'original_amount',
        'remaining_amount',
        'recovered_amount',
        'status',
        'credited_at',
        'frozen_at',
        'resolved_at',
        'metadata',
    ];

    protected $casts = [
        'original_amount' => 'integer',
        'remaining_amount' => 'integer',
        'recovered_amount' => 'integer',
        'credited_at' => 'datetime',
        'frozen_at' => 'datetime',
        'resolved_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sourceOrder()
    {
        return $this->belongsTo(Order::class, 'source_order_id');
    }

    public function creditTransaction()
    {
        return $this->belongsTo(WalletTransaction::class, 'credit_transaction_id');
    }

    public function allocations()
    {
        return $this->hasMany(WalletDebitAllocation::class, 'credit_lot_id');
    }
}
