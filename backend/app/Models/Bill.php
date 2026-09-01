<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bill extends Model
{
    use HasFactory,  SoftDeletes;

    protected $fillable = [
        'order_id',
        'user_id',
        'course_id',
        'bill_number',
        'amount',
        'tax_amount',
        'total_amount',
        'payment_status',
        'payment_method',
        'due_date',
        'paid_at',
        'notes'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    // Payment methods
    const PAYMENT_METHOD_ONLINE = 'online';
    const PAYMENT_METHOD_COURSE_CODE = 'course_code';
    const PAYMENT_METHOD_WALLET = 'wallet';

    // Payment statuses
    const PAYMENT_STATUS_PENDING = 'pending';
    const PAYMENT_STATUS_PAID = 'paid';
    const PAYMENT_STATUS_OVERDUE = 'overdue';
    const PAYMENT_STATUS_CANCELLED = 'cancelled';

    /**
     * Get the order for this bill.
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the user for this bill.
     */
    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Get the course for this bill.
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Scope for pending bills.
     */
    public function scopePending($query)
    {
        return $query->where('payment_status', self::PAYMENT_STATUS_PENDING);
    }

    /**
     * Scope for paid bills.
     */
    public function scopePaid($query)
    {
        return $query->where('payment_status', self::PAYMENT_STATUS_PAID);
    }

    /**
     * Check if bill is pending.
     */
    public function isPending()
    {
        return $this->payment_status === self::PAYMENT_STATUS_PENDING;
    }

    /**
     * Check if bill is paid.
     */
    public function isPaid()
    {
        return $this->payment_status === self::PAYMENT_STATUS_PAID;
    }

    /**
     * Mark bill as paid.
     */
    public function markAsPaid()
    {
        $this->update([
            'payment_status' => self::PAYMENT_STATUS_PAID,
            'paid_at' => now()
        ]);
    }

    /**
     * Generate bill number.
     */
    public static function generateBillNumber()
    {
        $prefix = 'BILL-';
        $year = date('Y');
        $month = date('m');

        $lastBill = self::whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderBy('id', 'desc')
            ->first();

            $sequence =  $lastBill ? (@end(explode('-', $lastBill->bill_number)) + 1) : 1;

            return sprintf('%s-%s%s-%s', $prefix, $year, $month, $sequence);
    }
}
