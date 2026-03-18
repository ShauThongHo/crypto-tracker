<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capital_flows', function (Blueprint $table) {
            // 如果是 MongoDB，Laravel-MongoDB 库会自动处理 ID，SQL 则使用 bigIncrements
            $table->id(); 

            // 1. 交易方向：DEPOSIT (入金), WITHDRAWAL (出金)
            $table->string('type')->comment('DEPOSIT or WITHDRAWAL');

            // 2. 法币金额：记录买入/卖出时花了多少法币
            // 使用 decimal 保证精度，15位总长，2位小数 (适合 MYR/SGD)
            $table->decimal('fiat_amount', 15, 2); 
            $table->string('fiat_currency')->default('MYR'); // MYR, SGD, USD

            // 3. 核心汇率：当时 P2P 的价格 (例如 4.75)
            $table->decimal('usdt_rate', 10, 4); 

            // 4. USDT 数量：fiat_amount / usdt_rate 算出来的结果
            $table->decimal('usdt_amount', 15, 4);

            // 5. 额外信息
            $table->string('platform')->nullable(); // Binance, OKX, Manual
            $table->date('transaction_date');       // 交易日期 (不带时分秒，方便按天统计)
            $table->text('notes')->nullable();      // 备注

            $table->timestamps();

            // 索引优化：方便以后按日期和类型快速统计盈亏
            $table->index(['type', 'transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capital_flows');
    }
};