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
        // MongoDB collections don't use Schema facade the same way
        // We use DB::collection() with createIndexes() for MongoDB

        // cex_synced_assets indexes
        $this->createCexSyncedAssetsIndexes();

        // assets indexes
        $this->createAssetsIndexes();

        // tracked_tokens indexes
        $this->createTrackedTokensIndexes();

        // capital_flows indexes
        $this->createCapitalFlowsIndexes();

        // asset_categories indexes
        $this->createAssetCategoriesIndexes();

        // asset_snapshots indexes
        $this->createAssetSnapshotsIndexes();

        // exchange_accounts indexes
        $this->createExchangeAccountsIndexes();
    }

    private function createCexSyncedAssetsIndexes(): void
    {
        $collection = \DB::connection('mongodb')->collection('cex_synced_assets');

        // Compound index for active assets by account
        $collection->createIndex(
            ['account_id' => 1, 'is_active' => 1, 'value_usd' => -1],
            ['name' => 'idx_account_active_value']
        );

        // Compound index for sync queries
        $collection->createIndex(
            ['exchange' => 1, 'account_id' => 1, 'sync_slot' => 1],
            ['name' => 'idx_exchange_account_sync']
        );

        // Symbol lookup
        $collection->createIndex(
            ['symbol' => 1, 'is_active' => 1],
            ['name' => 'idx_symbol_active']
        );

        // Last synced time for cache invalidation
        $collection->createIndex(
            ['last_synced_at' => -1],
            ['name' => 'idx_last_synced']
        );
    }

    private function createAssetsIndexes(): void
    {
        $collection = \DB::connection('mongodb')->collection('assets');

        // Source type and value for filtering
        $collection->createIndex(
            ['source_type' => 1, 'value_usd' => -1],
            ['name' => 'idx_source_type_value']
        );

        // CoinGecko ID for price lookups
        $collection->createIndex(
            ['coingecko_id' => 1],
            ['name' => 'idx_coingecko_id', 'sparse' => true]
        );

        // Network for grouping
        $collection->createIndex(
            ['network' => 1],
            ['name' => 'idx_network']
        );

        // Updated at for cache invalidation
        $collection->createIndex(
            ['updated_at' => -1],
            ['name' => 'idx_updated_at']
        );
    }

    private function createTrackedTokensIndexes(): void
    {
        $collection = \DB::connection('mongodb')->collection('tracked_tokens');

        // CoinGecko ID - primary lookup
        $collection->createIndex(
            ['coingecko_id' => 1],
            ['name' => 'idx_coingecko_id', 'unique' => true]
        );

        // Symbol for search
        $collection->createIndex(
            ['symbol' => 1],
            ['name' => 'idx_symbol']
        );

        // Name for search
        $collection->createIndex(
            ['name' => 1],
            ['name' => 'idx_name']
        );

        // Updated at for cache invalidation
        $collection->createIndex(
            ['updated_at' => -1],
            ['name' => 'idx_updated_at']
        );
    }

    private function createCapitalFlowsIndexes(): void
    {
        $collection = \DB::connection('mongodb')->collection('capital_flows');

        // Transaction date for time-range queries
        $collection->createIndex(
            ['transaction_date' => -1],
            ['name' => 'idx_transaction_date']
        );

        // Type for filtering deposits/withdrawals
        $collection->createIndex(
            ['type' => 1, 'transaction_date' => -1],
            ['name' => 'idx_type_date']
        );

        // Asset ID for asset-specific queries
        $collection->createIndex(
            ['asset_id' => 1, 'transaction_date' => -1],
            ['name' => 'idx_asset_date', 'sparse' => true]
        );
    }

    private function createAssetCategoriesIndexes(): void
    {
        $collection = \DB::connection('mongodb')->collection('asset_categories');

        // Name for uniqueness
        $collection->createIndex(
            ['name' => 1],
            ['name' => 'idx_name_unique', 'unique' => true, 'collation' => ['locale' => 'en', 'strength' => 2]]
        );

        // Target percentage for sorting
        $collection->createIndex(
            ['target_pct' => -1],
            ['name' => 'idx_target_pct']
        );

        // Updated at for cache invalidation
        $collection->createIndex(
            ['updated_at' => -1],
            ['name' => 'idx_updated_at']
        );
    }

    private function createAssetSnapshotsIndexes(): void
    {
        $collection = \DB::connection('mongodb')->collection('asset_snapshots');

        // Snapshot time for time-range queries
        $collection->createIndex(
            ['snapshot_time' => -1],
            ['name' => 'idx_snapshot_time']
        );

        // Compound for range queries with total value
        $collection->createIndex(
            ['snapshot_time' => -1, 'total_value_usd' => -1],
            ['name' => 'idx_time_value']
        );
    }

    private function createExchangeAccountsIndexes(): void
    {
        $collection = \DB::connection('mongodb')->collection('exchange_accounts');

        // Enabled accounts for sync
        $collection->createIndex(
            ['enabled' => 1, 'exchange' => 1],
            ['name' => 'idx_enabled_exchange']
        );

        // Last sync status
        $collection->createIndex(
            ['last_sync_status' => 1],
            ['name' => 'idx_last_sync_status']
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $collections = [
            'cex_synced_assets',
            'assets',
            'tracked_tokens',
            'capital_flows',
            'asset_categories',
            'asset_snapshots',
            'exchange_accounts',
        ];

        foreach ($collections as $collectionName) {
            try {
                $collection = \DB::connection('mongodb')->collection($collectionName);
                $indexes = $collection->listIndexes();
                foreach ($indexes as $index) {
                    $name = $index['name'] ?? '';
                    if ($name !== '_id_' && str_starts_with($name, 'idx_')) {
                        $collection->dropIndex($name);
                    }
                }
            } catch (\Throwable $e) {
                // Index might not exist, continue
            }
        }
    }
};