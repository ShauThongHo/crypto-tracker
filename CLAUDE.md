# Crypto Tracker Project - Agent Team Structure

## Overview
This document defines the agent team structure for modularizing and maintaining the Crypto Tracker application. The team consists of specialized agents responsible for different aspects of the system.

## Team Members

### 1. Backend Engineer (Primary)
**Responsibilities:**
- Laravel backend development and maintenance
- API endpoint design and implementation
- Database schema design and migrations
- Business logic implementation
- Service layer development
- Integration with external exchanges (OKX, Bitget)
- Authentication and authorization systems
- Validation and data integrity

**Key Areas:**
- app/Http/Controllers/
- app/Models/
- app/Services/
- app/Providers/
- app/Infrastructure/
- database/migrations/
- database/factories/
- database/seeders/
- routes/api.php
- routes/web.php (backend logic portions)

### 2. Frontend Engineer (Primary)
**Responsibilities:**
- User interface development and maintenance
- Client-side JavaScript/Vue.js development
- CSS/styling and responsive design
- User experience optimization
- Frontend state management
- API consumption and data visualization
- Asset management and build processes

**Key Areas:**
- resources/js/ (components, services, pages, dashboard)
- resources/css/
- resources/views/ (Blade templates)
- public/js/ (compiled assets)
- public/css/
- public/style.css
- vite.config.js
- package.json

### 3. Test Engineer (Primary)
**Responsibilities:**
- Test strategy and planning
- Unit test development (PHPUnit/Pest)
- Feature test development
- Integration testing
- Test automation and CI/CD integration
- Quality assurance and bug verification
- Performance testing considerations

**Key Areas:**
- tests/ directory
- Test creation and maintenance
- Testing strategy documentation
- Test data management

### 4. DevOps Engineer (Secondary)
**Responsibilities:**
- Deployment pipeline management
- Environment configuration and management
- Docker/containerization setup (if applicable)
- Server configuration and optimization
- Monitoring and logging setup
- Backup and disaster recovery planning
- Performance optimization

**Key Areas:**
- docker/ (if exists or to be created)
- deployment scripts
- server configuration files
- monitoring setup
- scaling considerations

### 5. Security Engineer (Secondary)
**Responsibilities:**
- Security audit and vulnerability assessment
- Authentication and authorization review
- Input validation and sanitization
- Secure coding practices enforcement
- Dependency vulnerability scanning
- Security compliance verification

**Key Areas:**
- app/Http/Middleware/
- app/Providers/AuthServiceProvider.php
- config/auth.php
- config/sanctum.php
- Validation rules in controllers/requests
- Security headers and protections

## Team Collaboration Patterns

### Daily Standup Pattern
- Each agent reports progress on their respective domains
- Identify cross-cutting concerns and dependencies
- Coordinate on API contracts between frontend and backend
- Share testing findings and quality metrics

### Code Review Process
- Backend Engineer reviews backend changes
- Frontend Engineer reviews frontend changes
- Test Engineer reviews test coverage and quality
- Security Engineer reviews security-sensitive changes
- DevOps Engineer reviews deployment-related changes

### Integration Points
- API contracts between frontend and backend teams
- Database schema changes coordinated between backend and DevOps
- Security considerations reviewed by Security Engineer
- Deployment readiness verified by DevOps Engineer

## Modularization Guidelines

### Module Boundaries
1. **Asset Management Module** - Handles cryptocurrency assets, categories, snapshots
2. **Exchange Integration Module** - Handles exchange account connections and synchronization
3. **Capital Flow Module** - Handles deposits, withdrawals, and financial tracking
4. **Wallet Management Module** - Handles cryptocurrency wallets and addresses
5. **Alerting System Module** - Handles balance alerts and notifications
6. **Dashboard & Analytics Module** - Handles data visualization and reporting
7. **Authentication & Authorization Module** - Handles user management and access control
8. **Core Infrastructure Module** - Handles shared services, utilities, and configurations

### Communication Protocols
- Backend exposes RESTful API endpoints for frontend consumption
- Frontend consumes APIs via fetch/AJAX calls
- Shared data transfer objects (DTOs) for consistent data structures
- Event-driven communication for loose coupling where appropriate
- Configuration shared via environment variables and config files

---

## Lessons Learned & Performance Optimization Notes (2026-06-27)

### Major Refactor: Balance Alert Snapshot Optimization

**Problem**: `buildBalanceAlertSnapshotPayload` in `AssetController` was a 270-line god method causing 2-5s response times due to:
- Loading ALL assets via multiple DB queries in request cycle
- Synchronous CoinGecko API calls per request
- Python subprocess spawning for image generation in HTTP request
- File-based cache driver (slow)
- No database indexes on MongoDB collections

**Solution Applied**:

#### 1. Extracted Service Layer (New Files)
| File | Purpose |
|------|---------|
| `app/Services/BalanceAlertService.php` | Core business logic with 3-layer caching |
| `app/Jobs/FetchCoinGeckoPrices.php` | Scheduled job (every 5 min) pre-warms price cache |
| `app/Jobs/GenerateBalanceAlertImage.php` | Queued job for async Python rendering + webhook |
| `database/migrations/2026_06_27_000000_add_balance_alert_indexes.php` | 7 collection compound indexes |

#### 2. Caching Strategy (Redis)
```bash
CACHE_DRIVER=redis     # Changed from 'file' in .env
```
| Cache Layer | Key Pattern | TTL | Invalidation |
|-------------|-------------|-----|--------------|
| Prices | `coingecko:price:{id}` | 5 min | Auto-expire / background job |
| Asset Snapshot | `balance_alert:assets_snapshot:{fingerprint}` | 1 hour | On asset data change (count/updated_at) |
| Allocations Fingerprint | `balance_alert:allocations_fingerprint` | 24 hours | On category change |

#### 3. Async Architecture
- **Scheduler** (`app/Console/Kernel.php`): `$schedule->job(new FetchCoinGeckoPrices())->everyFiveMinutes();`
- **Queue** (`QUEUE_CONNECTION=database`): Image generation dispatched to `alerts` queue
- Run workers: `php artisan queue:work --queue=alerts,default`

#### 4. Controller Cleanup
`AssetController` reduced from ~1900 to ~1700 lines:
- Injected `BalanceAlertService`
- Delegated `getBalanceAlertSnapshot()` and `sendBalanceAlertImage()`
- Removed 8 private methods: `resolveBalanceAlertAllocations()`, `getCachedBalanceAlertSnapshotPayload()`, `getBalanceAlertAllocationsFingerprint()`, `normalizeSnapshotCacheInput()`, `buildBalanceAlertSnapshotPayload()` (old), `resolvePythonBinary()`

### Critical Dependency: MongoDB PHP Extension
**Required**: `php_mongodb` DLL for PHP 8.5 ZTS x64
- Download: `php_mongodb-2.3.3-8.5-zts-vs17-x64.zip` from windows.php.net
- Extract `php_mongodb.dll` → `C:\Users\hosha\Documents\php\ext\`
- Add `extension=mongodb` to `php.ini`
- **Without this**: `Class "MongoDB\Driver\Manager" not found` - migrations and app won't run

### Pending Setup Commands (Run After Extension Install)
```bash
cd /c/Users/hosha/Desktop/crypto-tracker

# 1. Run migrations (creates indexes)
php artisan migrate --force

# 2. Create jobs table for queue
php artisan queue:table && php artisan migrate --force

# 3. Start queue worker (terminal 1)
php artisan queue:work --queue=alerts,default

# 4. Start scheduler (terminal 2)
php artisan schedule:work

# 5. Test endpoint (should be <200ms with cache hits)
curl -X POST http://localhost/api/balance-alert/snapshot \
  -H "Content-Type: application/json" \
  -d '{"prepare_threshold":3,"rebalance_threshold":5,"force_threshold":7.5}'
```

### Key Patterns to Reuse
1. **Granular cache keys** with fingerprints instead of monolithic payload hash
2. **Background price fetching** via scheduled job (eliminates API latency in request)
3. **Queue for heavy operations** (image generation, webhooks)
4. **Service injection** in controller constructor for clean delegation
5. **MongoDB compound indexes** on query patterns (account+active, coingecko_id, timestamps)

### Gotchas to Avoid
- ❌ Don't use `file` cache driver for high-frequency reads
- ❌ Don't make external API calls in request cycle
- ❌ Don't spawn subprocesses in HTTP handlers
- ❌ Don't use monolithic cache keys that include all data (causes constant misses)
- ✅ Do pre-warm caches via scheduled jobs
- ✅ Do offload rendering to queue workers
- ✅ Do create compound indexes matching query patterns

## Development Workflow

### Feature Development Process
1. **Planning**: All agents collaborate on feature requirements and design
2. **Assignment**: Tasks assigned to appropriate specialist agents
3. **Implementation**: Agents work in their respective domains
4. **Integration**: Regular integration points to ensure compatibility
5. **Testing**: Test Engineer creates/writes tests, all agents verify
6. **Review**: Cross-functional code review
7. **Deployment**: DevOps prepares deployment, Security verifies safety

### Issue Resolution Process
1. **Identification**: Any agent can identify issues
2. **Triage**: Determine which agent(s) should address the issue
3. **Assignment**: Assign to appropriate specialist
4. **Resolution**: Specialist implements fix
5. **Verification**: Test Engineer verifies fix
6. **Deployment**: Deploys through DevOps pipeline

## Success Metrics

### Quality Metrics
- Test coverage percentage (target: >80%)
- Code review turnaround time (<24 hours)
- Bug escape rate (target: <5% to production)
- Security scan results (no critical/high vulnerabilities)

### Velocity Metrics
- Feature completion rate
- Sprint predictability
- Deployment frequency
- Mean time to recovery (MTTR)

## Tools and Technologies

### Backend
- PHP 8.1+
- Laravel 10.x
- MySQL/PostgreSQL/SQLite
- Redis (for caching/queues)
- Laravel Sanctum (API authentication)
- PHPUnit/Pest (testing)

### Frontend
- JavaScript ES6+
- CSS3/SCSS
- Blade templating engine
- Vite (asset bundling)
- Chart.js or similar for data visualization

### DevOps
- Git (version control)
- Docker (containerization)
- CI/CD pipelines (GitHub Actions/GitLab CI)
- Monitoring (Laravel Telescope, custom metrics)
- Logging (Laravel logging, external services)

## Getting Started

### Onboarding New Agents
1. Review this CLAUDE.md document
2. Explore the project structure using the provided Glob and Read tools
3. Review existing code in your domain area
4. Set up local development environment
5. Review existing tests and testing patterns
6. Begin with small, well-defined tasks

### Communication Protocols
- Use clear, descriptive task or issue
- Use specific file paths when referencing code
- Reference relevant tests when discussing changes
- Document decisions that affect multiple team members
- Regular synchronization points for integration work

---
*This document should be updated as the team and project evolve.*