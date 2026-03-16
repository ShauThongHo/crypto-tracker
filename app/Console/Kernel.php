<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // 🎯 关键修改：改成 everyMinute()
        // 别担心会频繁抓取 API，因为 SyncCryptoData 内部有缓存锁，5分钟只会真正跑一次。
        // 这样改是为了确保 UptimeRobot 无论几点几分戳进来，任务都能被触发。
        $schedule->command('app:sync-crypto-data')->everyFiveMinute();

        // 如果你以后有汇率同步，可以加在这里
        // $schedule->command('app:sync-exchange-rates')->daily();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
