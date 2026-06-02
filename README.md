# CryptoMarketAnalyzer

A Laravel application that scans **Binance** and **Bitget** futures markets using **TradingView** technical analysis data to identify buy and sell opportunities across Daily, Weekly, and Monthly timeframes. Users are notified by email when actionable signals are detected for symbols in their watchlist.

## Features

- **TradingView Scanner Integration** — Uses TradingView's technical analysis scanner (RSI, MACD, EMA, oscillators) to generate BUY/SELL/STRONG signals
- **Multi-exchange support** — Binance USDT Perpetual Futures + Bitget USDT Mix Futures
- **Three timeframes** — Daily (1D), Weekly (1W), Monthly (1M)
- **Watchlist** — Per-user watchlist with per-timeframe notification toggles
- **Email notifications** — Receive a digest email when signals fire for watched symbols
- **Admin dashboard** — Filament v3 panel with signal table, stats widgets, and manual scan trigger
- **Scheduled scanning** — Laravel scheduler runs scans automatically

## Signal Logic

Signals are sourced from TradingView's scanner which aggregates:
- **Oscillators**: RSI, Stochastic, MACD, CCI, etc.
- **Moving Averages**: EMA 20/50/200, SMA, etc.
- **Recommendation**: STRONG_BUY / BUY / NEUTRAL / SELL / STRONG_SELL

A signal is saved when the combined technical recommendation is not NEUTRAL and has changed since the last scan window.

## Setup

```bash
# Clone & install
composer install

# Configure environment
cp .env.example .env
php artisan key:generate

# Run migrations & seed default watchlist
php artisan migrate --seed

# Start the dev server
php artisan serve
```

Admin dashboard: `http://localhost:8000/admin`
Default credentials: `admin@cryptomarket.com` / `password`

## Artisan Commands

```bash
# Scan all timeframes (full flexible scan)
php artisan markets:scan 1D            # daily
php artisan markets:scan 1W            # weekly
php artisan markets:scan 1M            # monthly

# Shorthand commands (also trigger user notifications)
php artisan markets:daily
php artisan markets:weekly
php artisan markets:monthly

# Options
php artisan markets:scan 1D --exchange=binance --top=100
```

## Scheduler

Add to crontab to enable automatic scanning:

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Schedule:

| Command | Frequency |
|---|---|
| `markets:daily` | Daily at 08:00 UTC |
| `markets:weekly` | Every Monday at 00:01 UTC |
| `markets:monthly` | 1st of each month at 00:05 UTC |

## Queue Worker

Notifications are queued. Run the worker in production:

```bash
php artisan queue:work --queue=default
```

## Environment Variables

| Variable | Description |
|---|---|
| `BINANCE_API_KEY` / `BINANCE_API_SECRET` | Optional — public APIs work without keys |
| `BITGET_API_KEY` / `BITGET_API_SECRET` / `BITGET_PASSPHRASE` | Optional |
| `SCANNER_TOP_SYMBOLS` | How many top-volume symbols to scan (default 50) |
| `MAIL_*` | SMTP settings for email notifications |

## Disclaimer

This tool provides technical analysis data from TradingView for informational purposes only. It is **not financial advice**. Always do your own research before trading. Crypto markets are highly volatile.
