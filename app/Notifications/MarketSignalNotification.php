<?php

namespace App\Notifications;

use App\Models\MarketSignal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MarketSignalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private array $signals,
        private string $timeframe,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $timeframeLabel = match($this->timeframe) {
            '1D' => 'Daily',
            '1W' => 'Weekly',
            '1M' => 'Monthly',
            default => $this->timeframe,
        };

        $buySignals = array_filter($this->signals, fn($s) => $s->isBullish());
        $sellSignals = array_filter($this->signals, fn($s) => $s->isBearish());

        $mail = (new MailMessage)
            ->subject("[CryptoScanner] {$timeframeLabel} Trading Signals - " . now()->format('Y-m-d'))
            ->greeting("Hello {$notifiable->name}!")
            ->line("Your {$timeframeLabel} market scan has found " . count($this->signals) . " trading opportunities.");

        if (!empty($buySignals)) {
            $mail->line('🟢 **BUY Signals:**');
            foreach (array_slice($buySignals, 0, 10) as $signal) {
                $mail->line("• **{$signal->symbol}** ({$signal->exchange}) — {$signal->signal_type} | RSI: " . number_format($signal->rsi ?? 0, 1) . " | Price: $" . number_format($signal->price ?? 0, 4));
            }
        }

        if (!empty($sellSignals)) {
            $mail->line('🔴 **SELL Signals:**');
            foreach (array_slice($sellSignals, 0, 10) as $signal) {
                $mail->line("• **{$signal->symbol}** ({$signal->exchange}) — {$signal->signal_type} | RSI: " . number_format($signal->rsi ?? 0, 1) . " | Price: $" . number_format($signal->price ?? 0, 4));
            }
        }

        return $mail
            ->action('View Dashboard', url('/admin'))
            ->line('Signals are generated from TradingView technical analysis data.')
            ->line('Always do your own research before trading. This is not financial advice.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'timeframe' => $this->timeframe,
            'signal_count' => count($this->signals),
            'buy_count' => count(array_filter($this->signals, fn($s) => $s->isBullish())),
            'sell_count' => count(array_filter($this->signals, fn($s) => $s->isBearish())),
            'signal_ids' => array_map(fn($s) => $s->id, $this->signals),
            'message' => count($this->signals) . " new {$this->timeframe} trading signals found",
        ];
    }
}
