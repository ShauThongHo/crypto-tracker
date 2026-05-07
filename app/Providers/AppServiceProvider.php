<?php

namespace App\Providers;

use App\Infrastructure\Exchange\ExchangeAdapterFactory;
use App\Infrastructure\Price\CoinGeckoPriceClient;
use App\Repositories\Interfaces\AppSettingRepositoryInterface;
use App\Repositories\Interfaces\AssetRepositoryInterface;
use App\Repositories\Interfaces\CapitalFlowRepositoryInterface;
use App\Repositories\Interfaces\CategoryRepositoryInterface;
use App\Repositories\Interfaces\ExchangeAccountRepositoryInterface;
use App\Repositories\Interfaces\CexSyncedAssetRepositoryInterface;
use App\Repositories\Interfaces\TrackedTokenRepositoryInterface;
use App\Repositories\MongoDB\MongoAppSettingRepository;
use App\Repositories\MongoDB\MongoAssetRepository;
use App\Repositories\MongoDB\MongoCapitalFlowRepository;
use App\Repositories\MongoDB\MongoCategoryRepository;
use App\Repositories\MongoDB\MongoCEXAccountRepository;
use App\Repositories\MongoDB\MongoCexSyncedAssetRepository;
use App\Repositories\MongoDB\MongoTrackedTokenRepository;
use App\Services\AssetManagementService;
use App\Services\BalanceAlertService;
use App\Services\CapitalFlowService;
use App\Services\CexSyncService;
use App\Services\PortfolioService;
use App\Services\PriceFetchService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CoinGeckoPriceClient::class, function () {
            return new CoinGeckoPriceClient();
        });

        $this->app->singleton(ExchangeAdapterFactory::class, function () {
            return new ExchangeAdapterFactory();
        });

        $this->app->singleton(PortfolioService::class);
        $this->app->singleton(AssetManagementService::class);
        $this->app->singleton(BalanceAlertService::class);
        $this->app->singleton(PriceFetchService::class);
        $this->app->singleton(CapitalFlowService::class);
        $this->app->singleton(CexSyncService::class);

        $this->app->bind(AssetRepositoryInterface::class, MongoAssetRepository::class);
        $this->app->bind(CategoryRepositoryInterface::class, MongoCategoryRepository::class);
        $this->app->bind(ExchangeAccountRepositoryInterface::class, MongoCEXAccountRepository::class);
        $this->app->bind(CexSyncedAssetRepositoryInterface::class, MongoCexSyncedAssetRepository::class);
        $this->app->bind(CapitalFlowRepositoryInterface::class, MongoCapitalFlowRepository::class);
        $this->app->bind(TrackedTokenRepositoryInterface::class, MongoTrackedTokenRepository::class);
        $this->app->bind(AppSettingRepositoryInterface::class, MongoAppSettingRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot()
    {
        if (config('app.env') !== 'local') {
            URL::forceScheme('https');
        }
    }
}
