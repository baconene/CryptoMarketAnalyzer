<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketSignal extends Model
{
    protected $fillable = [
        'exchange', 'symbol', 'timeframe', 'signal_type',
        'price', 'rsi', 'macd', 'macd_signal',
        'ema_20', 'ema_50', 'ema_200',
        'volume', 'change_24h',
        'buy_indicators', 'sell_indicators', 'neutral_indicators',
        'raw_data', 'notification_sent',
    ];

    protected $casts = [
        'raw_data' => 'array',
        'notification_sent' => 'boolean',
        'price' => 'float',
        'rsi' => 'float',
        'macd' => 'float',
        'macd_signal' => 'float',
        'ema_20' => 'float',
        'ema_50' => 'float',
        'ema_200' => 'float',
        'volume' => 'float',
        'change_24h' => 'float',
    ];

    public function notifications(): HasMany
    {
        return $this->hasMany(SignalNotification::class);
    }

    public function isBullish(): bool
    {
        return in_array($this->signal_type, ['BUY', 'STRONG_BUY']);
    }

    public function isBearish(): bool
    {
        return in_array($this->signal_type, ['SELL', 'STRONG_SELL']);
    }

    public function getSignalColorAttribute(): string
    {
        return match($this->signal_type) {
            'STRONG_BUY' => 'success',
            'BUY' => 'success',
            'STRONG_SELL' => 'danger',
            'SELL' => 'danger',
            default => 'warning',
        };
    }

    public function getTimeframeLabel(): string
    {
        return match($this->timeframe) {
            '1D' => 'Daily',
            '1W' => 'Weekly',
            '1M' => 'Monthly',
            default => $this->timeframe,
        };
    }

    public function scopeActionable($query)
    {
        return $query->whereIn('signal_type', ['BUY', 'STRONG_BUY', 'SELL', 'STRONG_SELL']);
    }

    public function scopeForExchange($query, string $exchange)
    {
        return $query->where('exchange', $exchange);
    }

    public function scopeForTimeframe($query, string $timeframe)
    {
        return $query->where('timeframe', $timeframe);
    }
}
