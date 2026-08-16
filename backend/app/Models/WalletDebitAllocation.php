<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class WalletDebitAllocation extends Model
{
    protected $fillable = [
        'wallet_transaction_id',
        'credit_lot_id',
        'course_order_id',
        'amount',
        'entitlement_scope',
        'allocated_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'allocated_at' => 'datetime',
    ];

    public function walletTransaction()
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    public function creditLot()
    {
        return $this->belongsTo(WalletCreditLot::class, 'credit_lot_id');
    }

    public function courseOrder()
    {
        return $this->belongsTo(Order::class, 'course_order_id');
    }
}
