# OSMS — Optical Store Management System

A multi-tenant B2B SaaS for optical retail: patient & prescription records, barcode POS,
frame/lens inventory, kanban order workflow, financial analytics, and Razorpay subscriptions.

Migrated from Next.js + Supabase to **Laravel 12 + Blade + Bootstrap 5 + MySQL** for
cost-efficient hosting on Hostinger Premium Shared Hosting.

## Tech stack

| Layer | Tech |
| --- | --- |
| Backend | Laravel 12 (PHP 8.2+) |
| Frontend | Blade + Bootstrap 5 (+ Alpine.js for the order builder) |
| Database | MySQL (production) / SQLite (local dev) |
| Auth | Laravel Breeze |
| Multi-tenancy | Eloquent global scope (`TenantScope`) — app-layer row isolation |
| PDF | barryvdh/laravel-dompdf |
| Excel | maatwebsite/excel |
| QR / Barcode | simplesoftwareio/simple-qrcode + JsBarcode (Code128) |
| Payments | Razorpay subscriptions |
| Hosting | Hostinger Premium Shared Hosting · osms.satvscript.com |

## Local setup

Requires PHP 8.2+, Composer, and Node.js.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite        # SQLite dev DB
php artisan migrate --seed
php artisan storage:link
npm install && npm run build
```

To serve locally, use **Laravel Herd** (the bundled PHP) — link the folder and open the
`.test` URL. (`php artisan serve` may fail to bind a port on some Windows/Herd setups.)

## Architecture

```
app/
├── Http/Controllers/Tenant/     # Patient, Inventory, Order, Analytics, Billing, Search
├── Http/Controllers/Superadmin/ # Platform panel
├── Http/Middleware/             # EnsureTenantOnboarded, EnsureUserRole
├── Models/                      # + Concerns/BelongsToTenant, Scopes/TenantScope
├── Services/                    # SkuService, BillingService
└── Exports/                     # LedgerExport (Excel)

resources/views/
├── layouts/        # app (tenant shell) + guest (auth)
├── tenant/         # patients, inventory, orders, analytics, billing dashboards
├── partials/       # sidebar, global-search, barcode-listener
└── components/     # metric-card, eye-record-card

routes/
├── web.php         # public, auth, onboarding, tenant group, superadmin, webhook
└── tenant.php      # all tenant module routes (auth + onboarded)
```

### Multi-tenant isolation

Every store-owned model uses the `BelongsToTenant` trait, which applies `TenantScope` —
a global query scope that constrains all reads/writes to the authenticated user's
`tenant_id` (superadmins bypass it). This replaces Supabase Row-Level Security at the
application layer. Verified by the test suite.

## Production deploy & operations (Hostinger)

After `git pull` on the server:

```bash
php artisan migrate --force
php artisan optimize          # cache config/routes/views
php artisan storage:link      # once, for logo uploads on the public disk
```

Set in the production `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`.

**Scheduler (required).** Subscription trials only expire and archived records only purge
if the Laravel scheduler runs. Add one cron entry:

```text
* * * * * cd /path/to/osms && php artisan schedule:run >> /dev/null 2>&1
```

It drives `subscriptions:reconcile` (02:15, expires lapsed trials) and `model:purge-trashed`
(02:00). Without it, the SaaS access enforcement still works live (it's derived), but stored
subscription state and the trash purge won't advance.

**Queue worker.** Mail and exports run on the `database` queue. Run a worker (cron-restarted):

```bash
php artisan queue:work --stop-when-empty   # or a supervised long-running worker
```

## Tests

```bash
php artisan test
```