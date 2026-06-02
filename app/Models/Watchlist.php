<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Watchlist extends Model
{
    protected $fillable = [
        'user_id', 'symbol', 'exchange',
        'notify_daily', 'notify_weekly', 'notify_monthly', 'is_active',
    ];

    protected $casts = [
        'notify_daily' => 'boolean',
        'notify_weekly' => 'boolean',
        'notify_monthly' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
