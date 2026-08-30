<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WhatsAppLinkToken extends Model
{
    protected $table = 'whatsapp_link_tokens';

    protected $fillable = [
        'user_id',
        'coin_earning_method_id',
        'token_hash',
        'expires_at',
        'consumed_at',
        'sender_phone_e164',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(CoinEarningMethod::class, 'coin_earning_method_id');
    }
}
