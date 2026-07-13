# Crypto Tracker Module Mapping

This document maps the project's functional modules to their respective files and directories to guide the agent team's modularization efforts.

## Module 1: Asset Management
**Description**: Handles cryptocurrency assets, categories, snapshots, and valuation
**Files/Directories**:
- `app/Http/Controllers/AssetController.php` (primary controller)
- `app/Models/Asset.php` (Eloquent model)
- `app/Services/` (potential service layer)
- `resources/views/*` (Blade views for asset management)
- `public/js/services/*` (potential service layer for frontend)
- `public/js/components/*` (potential components for asset UI)
- `public/js/pages/*` (page-specific asset logic)
- `routes/api.php` (API routes: /assets/*)
- `routes/web.php` (web routes for asset views)
- `database/migrations/*` (asset-related migrations)
- `config/*` (relevant configuration)

## Module 2: Exchange Integration
**Description**: Manages exchange account connections, synchronization, and data fetching
**Files/Directories**:
- `app/Http/Controllers/AssetController.php` (exchange-related methods)
- `app/Models/ExchangeAccount.php` (exchange account model)
- `app/Models/CexSyncedAsset.php` (synced assets from exchanges)
- `app/Infrastructure/Exchange/` (exchange adapter architecture)
  - `ExchangeAdapterFactory.php`
  - `Contracts/ExchangeAdapterInterface.php`
  - `Adapters/OkxExchangeAdapter.php`
  - `Adapters/BitgetExchangeAdapter.php`
- `app/Services/CexSyncService.php` (synchronization service)
- `app/Console/Commands/SyncCryptoData.php` (Artisan command for sync)
- `resources/views/*` (exchange management views)
- `public/js/pages/*` (exchange-related page logic)
- `routes/api.php` (API routes: /exchange-accounts/*, /cex/*)
- `routes/web.php` (web routes for exchange management)

## Module 3: Capital Flow Management
**Description**: Tracks deposits, withdrawals, and financial movements
**Files/Directories**:
- `app/Http/Controllers/AssetController.php` (capital flow methods)
- `app/Models/CapitalFlow.php` (capital flow model)
- `resources/views/*` (capital flow views: capital.blade.php, etc.)
- `public/js/pages/capital-page.js` (capital page logic)
- `public/js/components/*` (capital-related components)
- `public/js/services/CapitalService.js` (capital service layer)
- `routes/api.php` (API routes: /capital/*)
- `routes/web.php` (web routes for capital management)
- `database/migrations/2026_03_17_093427_create_capital_flows_table.php`
- `resources/views/balance-alert.blade.php` (related to capital alerts)

## Module 4: Wallet Management
**Description**: Manages cryptocurrency wallets and addresses
**Files/Directories**:
- `app/Http/Controllers/AssetController.php` (wallet methods)
- `resources/views/*` (wallet management views)
- `public/js/pages/*` (wallet-related page logic)
- `public/js/components/*` (wallet UI components)
- `routes/api.php` (API routes: /wallets/*)
- `routes/web.php` (web routes for wallet management)

## Module 5: Alerting System
**Description**: Handles balance alerts, notifications, and reporting
**Files/Directories**:
- `app/Http/Controllers/AssetController.php` (alert methods)
- `app/Http/Controllers/API/BalanceAlertController.php` (dedicated API controller)
- `resources/views/balance-alert.blade.php` (main alert view)
- `resources/views/balance-alert.blade.php` (alert configuration)
- `resources/views/balace-alert.blade.php` (alert notifications)
- `public/js/pages/balance-alert-page.js` (alert page logic)
- `public/js/components/*` (alert-related UI components)
- `tools/render_balance_alert_table.py` (alert reporting utility)
- `routes/api.php` (API routes: /balance-alert/*)
- `routes/web.php` (web routes for alert management)
- `config/*` (alert-related configuration)

## Module 6: Dashboard & Analytics
**Description**: Provides data visualization, dashboards, and analytical views
**Files/Directories**:
- `public/js/dashboard/` (core dashboard module)
  - `app.js` (main dashboard entry point)
  - `*.js` (modular components: api.js, render.js, utils.js, etc.)
- `resources/views/index.blade.php` (main dashboard view)
- `resources/views/history.blade.php` (historical data view)
- `resources/views/map.blade.php` (asset mapping view)
- `resources/views/layouts/app.blade.php` (main layout with ECharts)
- `public/js/pages/*` (page-specific dashboard logic)
- `public/js/components/*` (visualization components)
- `public/js/services/*` (data service layers)
- `routes/web.php` (routes: /, /history, etc.)

## Module 7: Authentication & Authorization
**Description**: Manages user authentication, roles, and access control
**Files/Directories**:
- `app/Http/Controllers/Controller.php` (base controller)
- `app/Http/Middleware/` (authentication middleware)
  - `Authenticate.php`
  - `RedirectIfAuthenticated.php`
  - `VerifyCsrfToken.php`
  - etc.
- `app/Providers/AuthServiceProvider.php` (auth service provider)
- `config/auth.php` (authentication configuration)
- `config/sanctum.php` (Sanctum API auth configuration)
- `resources/views/layouts/app.blade.php` (auth layout elements)
- `resources/views/*` (auth views if custom)
- `routes/api.php` (auth routes: /user)
- `routes/web.php` (auth routes if applicable)

## Module 8: Core Infrastructure
**Description**: Shared utilities, configuration, and foundational elements
**Files/Directories**:
- `app/Providers/` (all service providers)
- `app/Services/` (shared services)
- `app/Exceptions/` (exception handling)
- `app/Http/Kernel.php` (HTTP kernel)
- `app/Console/Kernel.php` (console kernel)
- `config/` (all configuration files)
- `database/` (migrations, seeders, factories)
- `resources/css/` (stylesheets)
- `resources/js/bootstrap.js` (bootstrap initialization)
- `public/` (public assets)
- `routes/` (route definitions)
- `bootstrap/` (application bootstrapping)
- `storage/` (storage and caching)
- `vendor/` (third-party dependencies)
- `.env` & `.env.example` (environment configuration)
- `composer.json` & `composer.lock` (PHP dependencies)
- `package.json` (Node.js dependencies)
- `vite.config.js` (frontend build configuration)
- `Dockerfile` (containerization)
- `tests/` (testing infrastructure)
- `ARTISAN` (Laravel CLI)

## Cross-Module Dependencies

### Database Relationships
- `Asset` model relates to `AssetCategory` (through category_id)
- `Asset` has many `CapitalFlow` records
- `ExchangeAccount` has many `CexSyncedAsset` records
- User authentication relates to all owned resources

### API Contracts
Backend provides RESTful API endpoints consumed by frontend:
- `GET /api/assets` - Get user's assets
- `POST /api/assets` - Create new asset
- `GET /api/exchange-accounts` - Get exchange accounts
- `POST /api/exchange-accounts` - Add exchange account
- `GET /api/capital/history` - Get capital flow history
- `GET /api/wallets` - Get wallets
- `GET /api/balance-alert/snapshot` - Get alert snapshot
- etc.

### Shared Services
- Authentication service (Sanctum)
- Logging facilities
- Cache systems
- Queue systems (if implemented)
- Event broadcasting (if implemented)

## Implementation Guidelines for Agents

### Backend Engineer Focus
- Maintain clean separation between controllers, services, and models
- Implement repository patterns where appropriate
- Ensure proper validation and error handling
- Maintain API documentation and consistency
- Optimize database queries and relationships

### Frontend Engineer Focus
- Maintain modular JavaScript structure (ES modules)
- Create reusable Vue components (if adopting Vue framework)
- Implement consistent state management
- Ensure responsive design and accessibility
- Optimize asset loading and performance

### Test Engineer Focus
- Create unit tests for business logic
- Create feature tests for API endpoints
- Create browser tests for critical user flows
- Maintain test coverage above 80%
- Implement automated testing in CI/CD

### DevOps Engineer Focus
- Maintain and optimize Dockerfile
- Implement CI/CD pipelines
- Monitor application performance and health
- Manage environment configurations
- Ensure proper logging and monitoring

### Security Engineer Focus
- Regular security audits of code and dependencies
- Validate authentication and authorization implementations
- Review input validation and output escaping
- Monitor for vulnerabilities in dependencies
- Ensure compliance with security best practices

## Future Modularization Opportunities
1. Extract service interfaces and implement dependency injection
2. Implement event-driven architecture for loose coupling
3. Create dedicated service classes for business logic
4. Implement API resources/transformers for consistent responses
5. Create form request classes for validation
6. Implement policy-based authorization
7. Create dedicated namespace for API controllers (App\Http\Controllers\API)
8. Implement caching strategies for expensive operations
9. Add message queues for asynchronous processing
10. Implement comprehensive logging and monitoring