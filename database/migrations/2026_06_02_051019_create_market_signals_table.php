<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('market_signals', function (Blueprint $table) {
            $table->id();
            $table->string('exchange');
            $table->string('symbol');
            $table->string('timeframe');
            $table->string('signal_type');
            $table->decimal('price', 20, 8)->nullable();
            $table->decimal('rsi', 8, 4)->nullable();
            $table->decimal('macd', 20, 8)->nullable();
            $table->decimal('macd_signal', 20, 8)->nullable();
            $table->decimal('ema_20', 20, 8)->nullable();
            $table->decimal('ema_50', 20, 8)->nullable();
            $table->decimal('ema_200', 20, 8)->nullable();
            $table->decimal('volume', 30, 4)->nullable();
            $table->decimal('change_24h', 8, 4)->nullable();
            $table->integer('buy_indicators')->default(0);
            $table->integer('sell_indicators')->default(0);
            $table->integer('neutral_indicators')->default(0);
            $table->json('raw_data')->nullable();
            $table->boolean('notification_sent')->default(false);
            $table->timestamps();

            $table->index(['exchange', 'symbol', 'timeframe']);
            $table->index(['signal_type', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('market_signals');
    }
};
