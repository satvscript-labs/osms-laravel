# OSMS — Optical Store Management System

A multi-tenant B2B SaaS for optical retail: patient & prescription records, barcode POS,
frame/lens inventory, kanban order workflow, financial analytics, WhatsApp customer
messaging, and Razorpay subscriptions.

Built on **Laravel 12 + Blade + Bootstrap 5 + MySQL**, with an iOS-inspired premium
design system (design tokens, spring-eased motion, live-search list pages throughout).

## Tech stack

| Layer | Tech |
| --- | --- |
| Backend | Laravel 12 (PHP 8.2+) |
| Frontend | Blade + Bootstrap 5 (+ Alpine.js for the order builder and live search) |
| Database | MySQL (production) / SQLite (local dev) |
| Auth | Laravel Breeze + TOTP two-factor authentication |
| Multi-tenancy | Eloquent global scope (`TenantScope`) — app-layer row isolation |
| PDF | barryvdh/laravel-dompdf |
| Excel | maatwebsite/excel |
| QR / Barcode | simplesoftwareio/simple-qrcode + JsBarcode (Code128) |
| Payments | Razorpay subscriptions |
| Security | Content-Security-Policy, self-hosted fonts, security response headers |

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
├── Http/Controllers/Tenant/     # Patient, Inventory, Order, Analytics, Billing,
│                                 # Search, WhatsApp Settings, Staff, Activity
├── Http/Controllers/Superadmin/ # Platform panel
├── Http/Middleware/             # EnsureTenantOnboarded, EnsureUserRole, SecurityHeaders
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
`tenant_id` (superadmins bypass it). Verified by the test suite.

## Tests

```bash
php artisan test
```
