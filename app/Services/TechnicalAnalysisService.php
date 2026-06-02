<?php

namespace App\Services;

class TechnicalAnalysisService
{
    public function analyze(array $klines): array
    {
        if (count($klines) < 30) {
            return $this->emptyResult();
        }

        $closes = array_column($klines, 'close');
        $volumes = array_column($klines, 'volume');

        $rsi = $this->rsi($closes);
        $emaResult = $this->emas($closes);
        $macdResult = $this->macd($closes);
        $currentPrice = end($closes);

        [$signal, $buy, $sell, $neutral] = $this->generateSignal(
            $currentPrice, $rsi, $emaResult, $macdResult
        );

        return [
            'signal_type' => $signal,
            'price' => $currentPrice,
            'rsi' => $rsi,
            'macd' => $macdResult['macd_line'] ?? null,
            'macd_signal' => $macdResult['signal_line'] ?? null,
            'ema_20' => $emaResult['ema_20'] ?? null,
            'ema_50' => $emaResult['ema_50'] ?? null,
            'ema_200' => $emaResult['ema_200'] ?? null,
            'volume' => end($volumes),
            'buy_indicators' => $buy,
            'sell_indicators' => $sell,
            'neutral_indicators' => $neutral,
        ];
    }

    private function rsi(array $closes, int $period = 14): ?float
    {
        if (count($closes) < $period + 1) {
            return null;
        }

        $gains = [];
        $losses = [];

        for ($i = 1; $i <= $period; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $gains[] = max($change, 0);
            $losses[] = max(-$change, 0);
        }

        $avgGain = array_sum($gains) / $period;
        $avgLoss = array_sum($losses) / $period;

        $len = count($closes);
        for ($i = $period + 1; $i < $len; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $avgGain = (($avgGain * ($period - 1)) + max($change, 0)) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + max(-$change, 0)) / $period;
        }

        if ($avgLoss == 0) {
            return 100.0;
        }

        $rs = $avgGain / $avgLoss;
        return round(100 - (100 / (1 + $rs)), 2);
    }

    private function ema(array $closes, int $period): ?float
    {
        if (count($closes) < $period) {
            return null;
        }

        $k = 2 / ($period + 1);
        $ema = array_sum(array_slice($closes, 0, $period)) / $period;

        for ($i = $period; $i < count($closes); $i++) {
            $ema = ($closes[$i] * $k) + ($ema * (1 - $k));
        }

        return $ema;
    }

    private function emaArray(array $closes, int $period): array
    {
        if (count($closes) < $period) {
            return [];
        }

        $k = 2 / ($period + 1);
        $ema = array_sum(array_slice($closes, 0, $period)) / $period;
        $result = array_fill(0, $period - 1, null);
        $result[] = $ema;

        for ($i = $period; $i < count($closes); $i++) {
            $ema = ($closes[$i] * $k) + ($ema * (1 - $k));
            $result[] = $ema;
        }

        return $result;
    }

    private function emas(array $closes): array
    {
        return [
            'ema_20' => $this->ema($closes, 20),
            'ema_50' => $this->ema($closes, 50),
            'ema_200' => $this->ema($closes, 200),
        ];
    }

    private function macd(array $closes, int $fast = 12, int $slow = 26, int $signal = 9): array
    {
        if (count($closes) < $slow + $signal) {
            return ['macd_line' => null, 'signal_line' => null, 'histogram' => null];
        }

        $fastEma = $this->emaArray($closes, $fast);
        $slowEma = $this->emaArray($closes, $slow);

        $macdLine = [];
        for ($i = 0; $i < count($closes); $i++) {
            if ($fastEma[$i] !== null && $slowEma[$i] !== null) {
                $macdLine[] = $fastEma[$i] - $slowEma[$i];
            }
        }

        if (count($macdLine) < $signal) {
            return ['macd_line' => end($macdLine) ?: null, 'signal_line' => null, 'histogram' => null];
        }

        $signalLine = $this->ema($macdLine, $signal);
        $currentMacd = end($macdLine);

        return [
            'macd_line' => $currentMacd,
            'signal_line' => $signalLine,
            'histogram' => $signalLine !== null ? $currentMacd - $signalLine : null,
        ];
    }

    private function generateSignal(float $price, ?float $rsi, array $emas, array $macd): array
    {
        $buy = 0;
        $sell = 0;
        $neutral = 0;

        // RSI
        if ($rsi !== null) {
            if ($rsi < 30) $buy++;
            elseif ($rsi > 70) $sell++;
            else $neutral++;
        }

        // MACD crossover
        if ($macd['macd_line'] !== null && $macd['signal_line'] !== null) {
            if ($macd['macd_line'] > $macd['signal_line']) $buy++;
            elseif ($macd['macd_line'] < $macd['signal_line']) $sell++;
            else $neutral++;

            // MACD above/below zero
            if ($macd['macd_line'] > 0) $buy++;
            else $sell++;
        }

        // Price vs EMAs
        foreach (['ema_20', 'ema_50', 'ema_200'] as $ema) {
            if ($emas[$ema] !== null) {
                if ($price > $emas[$ema]) $buy++;
                else $sell++;
            }
        }

        // EMA alignment (trend)
        if ($emas['ema_20'] !== null && $emas['ema_50'] !== null) {
            if ($emas['ema_20'] > $emas['ema_50']) $buy++;
            else $sell++;
        }

        if ($emas['ema_50'] !== null && $emas['ema_200'] !== null) {
            if ($emas['ema_50'] > $emas['ema_200']) $buy++;
            else $sell++;
        }

        $total = $buy + $sell + $neutral;

        $signal = 'NEUTRAL';
        if ($total > 0) {
            $buyPct = $buy / $total;
            $sellPct = $sell / $total;

            if ($buyPct >= 0.75) $signal = 'STRONG_BUY';
            elseif ($buyPct >= 0.55) $signal = 'BUY';
            elseif ($sellPct >= 0.75) $signal = 'STRONG_SELL';
            elseif ($sellPct >= 0.55) $signal = 'SELL';
        }

        return [$signal, $buy, $sell, $neutral];
    }

    private function emptyResult(): array
    {
        return [
            'signal_type' => 'NEUTRAL',
            'price' => null,
            'rsi' => null,
            'macd' => null,
            'macd_signal' => null,
            'ema_20' => null,
            'ema_50' => null,
            'ema_200' => null,
            'volume' => null,
            'buy_indicators' => 0,
            'sell_indicators' => 0,
            'neutral_indicators' => 0,
        ];
    }
}
