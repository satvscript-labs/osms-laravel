# OSMS Laravel — Feature Tracker

**Companion:** [QA_TESTING_REPORT_3.md](QA_TESTING_REPORT_3.md) (Session 3 impact analysis) ·
[BUG_TRACKER.md](BUG_TRACKER.md) (defects + earlier feature gaps).
**Scope:** planned **feature work** (net-new capabilities), its build order, and status. Deep
per-feature impact analysis and the butterfly maps live in
[QA_TESTING_REPORT_3.md](QA_TESTING_REPORT_3.md); this file is the **build tracker** — what we're
building, in what order, and where each stands.

> Every feature must follow the `CLAUDE.md` [VISUAL DESIGN SYSTEM DIRECTIVE] (premium iOS-inspired UI,
> design tokens only, spring-eased motion) and ship with a `PhaseNN…Test` suite carrying a
> tenant-isolation assertion for every new tenant-owned action.

---

## Locked decisions (Session 3)

Settled with the product owner on 2026-07-01 (see [QA_TESTING_REPORT_3.md](QA_TESTING_REPORT_3.md)
"Open decisions"):

- **D1 — Customer model:** **unify** into one `Customer` entity ("patient" = a customer who has a
  prescription). **Plus** inline **auto-register on order creation** — no separate registration step
  (find-or-create by `(tenant_id, phone)`).
- **D2 — Walk-ins:** name + phone **stay required** (`customer_id`/`phone` remain NOT NULL); speed
  comes from inline create, not nullable fields.
- **D6 — Permissions:** **all staff** may apply discounts / custom prices.
- **D7 — Analytics:** revenue = **net of discount**, with the order-level discount **allocated
  pro-rata to lines** so brand revenue reconciles.
- **Defaults (confirm to change):** D3 one "Customers" section + a "Patients" filter · D4 rename
  `patients.*` → `customers.*` now · D5 order-level discount + per-line custom price · D8 snapshot
  per-line `list_price` · D9 below-cost allow-but-warn · D10 `selling_price` = list/MRP · D11 barcode
  Print + Download PNG named by SKU.

Settled with the product owner on 2026-07-02 (Task 4 — fulfillment):
- **D-status:** an **instant** sale is created directly as `delivered` (skips `pending`/
  `ready_for_pickup`); a **special** order keeps the unchanged `pending` start.
- **D-date:** `estimated_ready_at` is **required** when `fulfillment_type = special`.
- **D-payment:** the existing flexible advance/balance flow is **unchanged** for both types.
- **D-dashboard:** add a new **"Due to prepare"** alert (not just a passive date on the card).
- **D-instant-cancel:** **no** cancel relaxation — a `delivered` order stays closed for both types; a
  mis-rung instant sale is corrected via manual stock adjustment, not a new return/cancel path.

---

## Build order

Barcode ships first (independent, zero shared surface). Customers is the foundational rewire, then the
order money-model. Pricing semantics (Task 3.1) is folded into the order money-model work.

| # | Ref | Feature | Depends on | Priority | Status |
| --- | --- | --- | --- | --- | --- |
| 1 | FT-Barcode | Printable / downloadable barcode label on Edit item (Task 3.2) | — | Low | ✅ Done |
| 2 | FT-Customers | Unified customer entity + inline auto-register in orders (Task 1) | — | High | ✅ Done |
| 3 | FT-OrderMoney | Order discount + custom price + advance payment method + builder redesign (Task 2, incl. 3.1 pricing semantics) | FT-Customers | High | ✅ Done |
| 4 | FT-Fulfillment | Instant (grab & go) vs. special (prepared, estimated ready date) order fulfillment (Task 4) | FT-Customers | Medium | ✅ Done |

---

## FT-Barcode — Printable / downloadable barcode label (Task 3.2)

- **Status:** ✅ Done (2026-07-01).
- **Priority:** Low (additive, independent — touches nothing FT-Customers / FT-OrderMoney touch).
- **Scope:** On **Edit item**, a "Barcode label" panel renders the item's `barcode` as a **Code128**
  symbol (client-side via the already-bundled `JsBarcode`) with the **SKU** as the human-readable line.
  **Download** saves a PNG named `{SKU}.png`; **Print** opens a print-friendly label window (title =
  SKU, so print-to-PDF also defaults to the SKU).
- **Why client-side:** no new dependency, fully offline, and the rendered Code128 of the `barcode`
  value round-trips through [`InventoryController::scan`](app/Http/Controllers/Tenant/InventoryController.php)
  (which matches `barcode` or `sku`).
- **Location:** [inventory/edit.blade.php](resources/views/tenant/inventory/edit.blade.php) (panel +
  guarded inline script); relies on [`JsBarcode`](resources/js/app.js).
- **Edge cases handled:** SKU sanitised for a safe filename; label shows brand/model above the code;
  script is `DOMContentLoaded`-guarded with an `if (!window.JsBarcode) return;` bail (mirrors the
  BUG-001 / NB-002 deferred-ESM pattern).
- **Tests:** front-end render is manual (canvas/SVG); `Phase15BarcodeLabelTest` (3) asserts the edit
  page exposes the barcode panel + Download/Print controls and the SKU/barcode data, the label↔scan
  round-trip resolves, and the page is tenant-isolated. Suite: **152 passed / 601 assertions**.
- **Commit:** `ccb3902`.

---

## FT-Customers — Unified customer entity + inline auto-register (Task 1)

- **Status:** ✅ Done (2026-07-01) — shipped in 4 steps (C-a…C-d).
- **Priority:** High (foundational — highest churn, lowest math risk).

### Shipped
- **C-a (`482e9fb`)** — foundation rename `patient` → `customer` across schema (portable, reversible
  migration: `Schema::rename` + `renameColumn`, FKs preserved), models, controllers, routes, views,
  exports, request, seeder, tests. "Patient" kept as clinical/marketing wording. Dev DB migrated
  (data preserved).
- **C-b (`9548195`)** — inline auto-register in the order builder: `store` accepts `customer_id` OR
  new `customer_name`+`customer_phone` and find-or-creates by `(tenant_id, phone)` (existing phone
  reuses, never renames; unique index backstops). Alpine "Add '<typed>' as a new customer".
- **UI fix (`f8eed04`)** — `x-cloak` on the picker states to stop Alpine FOUC (stray "Change" button).
- **C-c (`eb102be`)** — "Patient" as a derived role: `filter=patients` (scopePatients),
  `withCount('eyeRecords')`, All/Patients segmented filter + blue "Patient" badge.
- **C-d** — route smoke test: `Phase1SmokeTest::test_all_tenant_get_routes_respond_without_error`
  GETs every tenant route (with seeded bindings) asserting < 500 — the net for missed `route()` refs.

### Tests
`Phase16CustomerInlineTest` (7), `Phase17CustomersFilterTest` (4), route smoke sweep, plus the full
renamed suite. **164 passed / 662 assertions.**

### ⚠️ Deploy note (Hostinger / MySQL)
The rename migration was verified on SQLite only (no local MySQL). **Before deploying: back up the
production DB**, then `php artisan migrate` (+ `php artisan optimize:clear` for route/view cache). The
migration renames the `patients` table and the `orders`/`eye_records` FK columns via Laravel's portable
grammar; existing rows are preserved.

### Guiding principle
A **rename of the contact entity** (`patient` → `customer`), **not** a blanket find-replace. "Patient"
survives as a **derived clinical role** = a customer with ≥ 1 eye record (derived via
`eyeRecords()->exists()`, **no `is_patient` column** — can't drift). Clinical wording (eye records /
prescriptions), the "Patients" filter/badge label, and `config/billing.php` marketing copy stay.

### Locked decisions (this feature)
- **No backward-compat shim** — clean rename; a new route **smoke test** + the full suite are the net.
- **Migration** = data-preserving `Schema::rename` + `renameColumn`, verified on SQLite (tests) **and a
  scratch MySQL DB before deploy** (SQLite-green ≠ MySQL-safe for FK/column renames).

### Migration (#1 risk)
New migration `..._rename_patients_to_customers` (never edit historical migrations — Hostinger already
ran them): (1) `Schema::rename('patients','customers')` — FK refs in `orders`/`eye_records` follow the
rename on both engines; (2) `renameColumn('patient_id'→'customer_id')` on `orders` + `eye_records`
(Laravel 12 native); (3) reversible `down()`. Both FKs are `cascadeOnDelete` today — preserve exactly.

### Inline auto-register (D1)
`OrderController::store`/`update` accept **either** `customer_id` **or** `customer_name`+`customer_phone`
(conditional `required_without`); inside the existing transaction, **find-or-create by
`(tenant_id, phone)`** (`firstOrCreate`, backstopped by the unique index — no race dup; existing phone
reuses, never overwrites the name). Alpine builder: search → if no match, "Add '{typed}'" reveals
name+phone; hidden fields carry `customer_id` or the new pair.

### Blast radius (verified; excludes regenerable `storage/framework/views`)
Schema (`patients`, `orders.patient_id`, `eye_records.patient_id`) · Models (`Patient`,
`Order.patient()`, `EyeRecord.patient()`, `Tenant.patients()`) · Controllers (`Patient`→`Customer`,
`Order`, `EyeRecord`, `Search`, `Analytics`, tenant + superadmin `Dashboard`) · `StorePatientRequest`,
`PatientsExport`, `LedgerExport`, `PurgeTrashedRecords` · routes (`patients.*`→`customers.*`) · views
(`patients/*`, orders `create/edit/show/receipt-pdf/partials`, `analytics`, dashboards, sidebar,
global-search) · ~11 test files (no `PatientFactory` — direct `create`) · `DatabaseSeeder`.

### Build sequence (each green before the next)
- **C-a — Foundation rename (atomic):** migration + models + controllers + routes + views + exports +
  request + seeder + updated tests → suite green + MySQL dry-run.
- **C-b — Inline auto-register** (server find-or-create + Alpine UI) + tests.
- **C-c — "Patients" filter + badge** (derived role) on the customers index + tests.
- **C-d — Smoke-test hardening** (extend `Phase1SmokeTest` to GET every tenant route, assert non-500) +
  docs (this tracker → Done, deploy note).

### Tests
`PhaseNNCustomersTest` — CRUD + tenant isolation, inline auto-register (find-or-create + reuse by
phone), promote-to-patient via eye record, order for a plain customer, migration/backfill correctness,
global search returns customers, patients filter. Plus the route smoke test.

**Impact analysis + full butterfly list:** [QA_TESTING_REPORT_3.md](QA_TESTING_REPORT_3.md) → Task 1.

---

## FT-OrderMoney — Discount + custom price + advance method + redesign (Task 2 + 3.1)

- **Status:** ✅ Done (2026-07-02) — shipped in 3 steps (M-a…M-c).
- **Priority:** High.

### Shipped
- **M-a (`621e18d`)** — money model + server. Migration adds `subtotal` /
  `discount_type` / `discount_value` / `discount_amount` to orders and `list_price`
  to order_items (backward-compatible backfill; portable + reversible).
  `total_amount = subtotal − discount_amount`. `store()`/`update()` accept per-line
  custom `unit_price` (list_price snapshots MRP), an order-level discount, and the
  advance payment method; `resolveDiscount()` clamps percent 0–100 and amount ≤
  subtotal; advance clamps to the discounted total; edit preserves the discount when
  not re-posted. Below-cost allowed (NB-004). `Phase18OrderMoneyTest` (12).
- **M-b (`adac7bd`)** — premium builder redesign (create + edit): inline-editable
  unit price with list/MRP hint + "reset to list"; segmented **% / ₹** discount with
  live "you save"; advance + payment-method; consistent flex alignment; `x-cloak` on
  all conditional states (kills the Alpine FOUC).
- **M-c** — receipt PDF + order show now render Subtotal → Discount → Total →
  Advance (+ paid-via methods) → Balance. Analytics: revenue **net** of discount, a
  **Discounts given** KPI, and brand revenue **allocated pro-rata** to lines so it
  reconciles to net revenue (D7). `Phase19AnalyticsMoneyTest` (4).

### Tests
`Phase18OrderMoneyTest` (12) + `Phase19AnalyticsMoneyTest` (4) + full suite.
**180 passed / 718 assertions.**

### ⚠️ Deploy note (Hostinger / MySQL)
`php artisan migrate` applies the discount/list_price migration (portable, additive,
backfills existing rows). Run `php artisan optimize:clear` after deploy.

### Scope (original)
  - **Discount** (percent or amount) with live calc: add `subtotal`, `discount_type`, `discount_value`,
    `discount_amount` to `orders`; `total_amount = subtotal − discount_amount`.
  - **Custom selling price** per line (already stored in `order_items.unit_price`); snapshot
    `list_price` per line for discount reporting. Below-cost allow-but-warn (NB-004).
  - **Advance payment method** captured on the initial `Payment` (drops the hardcoded cash); surfaced
    on the receipt + reports.
  - **Create/Edit order redesign** to absorb the customer picker + these controls without clutter.
  - **Pricing semantics (Task 3.1):** `selling_price` = default/list (MRP); revenue always reads the
    order line price.
- **Impact analysis + full butterfly list:** [QA_TESTING_REPORT_3.md](QA_TESTING_REPORT_3.md) → Task 2
  & Task 3.1. **Highest-care area:** analytics revenue = net + **pro-rata discount allocation** so
  brand revenue reconciles (D7).
- **Interactions:** must re-reconcile with the FG-OrderEdit flow (C3) — discount + custom prices
  preserved on edit; rounding defined consistently (UI / server / PDF / analytics).
- **Tests:** `PhaseNNOrderMoneyTest` — discount clamps (percent/amount), custom price (below-cost
  warn), advance method persisted, receipt renders discount + method, analytics net revenue +
  brand reconciliation, backward-compat (no discount → old totals), edit interaction.

---

## FT-Fulfillment — Instant vs. special-order fulfillment (Task 4)

- **Status:** ✅ Done (2026-07-02) — shipped in 3 steps (F-a…F-c).
- **Priority:** Medium.
- **Depends on:** FT-Customers (edits the same `store()`/builder).

### Shipped
- **F-a (`73a1ead`)** — schema + server. Migration adds `fulfillment_type`
  (instant|special, default special backfill) + `estimated_ready_at` (nullable date);
  additive, portable, reversible. `store()`: instant → created `delivered` (skips the
  prep pipeline); special → pending + a required ready date (`after_or_equal:today`).
  Stock/discount/custom-price/payment unchanged for both; defaults to special when the
  field is absent (backward compatible). `update()`: ready date editable on open orders.
  `Phase20FulfillmentTest` (7).
- **F-b (`d38198e`)** — order builder: a "Fulfillment" card with two selectable tiles
  (Take today / Special order), a revealed estimated-ready date picker for special
  (default today+3, min today), submit label reflecting the choice, and `canSubmit`
  gating the date. Edit exposes an editable ready date for special orders.
- **F-c** — dashboard **"Due to prepare"** alert (pending + special + date ≤ today, with
  overdue-day count), kanban "In lab" cards show a ready-date badge (red once overdue),
  and the order show page + receipt PDF render the fulfillment type + estimated-ready
  line for special orders. `Phase21FulfillmentDashboardTest` (5).

### Tests
`Phase20FulfillmentTest` (7) + `Phase21FulfillmentDashboardTest` (5) + full suite.
**192 passed / 750 assertions.**

### ⚠️ Deploy note (Hostinger / MySQL)
`php artisan migrate` applies the additive fulfillment columns (backfills existing rows
to `special`). Run `php artisan optimize:clear` after deploy.

### Why
Most walk-in customers either grab an in-stock item, pay, and leave (nothing to track), or place a
special order needing lab time (lens grinding/fitting), for which the owner gives an estimated ready
date. Today every order is forced through the same `pending → ready_for_pickup → delivered` pipeline
regardless of which kind it is.

### Scope
- New `orders.fulfillment_type` (`instant | special`) + `orders.estimated_ready_at` (nullable date).
- **Instant** → created directly as `delivered` (skips `pending`/`ready_for_pickup`); stock, discount,
  custom price, and payment all work exactly as today — only the initial `status` differs.
- **Special** → unchanged `pending` start, **plus a required `estimated_ready_at`** (date picker,
  default today+3, adjustable); flows through the existing kanban pipeline unchanged.
- New dashboard **"Due to prepare"** alert: `pending` + `special` orders whose `estimated_ready_at` is
  today or past — alongside the existing "uncollected > 3 days" alert.
- Kanban `pending` ("In lab") cards show the estimated date (red once overdue).
- `estimated_ready_at` becomes editable via `update()` for still-open orders (FG-OrderEdit).
- Receipt/order show: "Estimated ready: `DATE`" line for special orders.

### Locked decisions (this feature)
- Payments: unchanged flexible advance/balance flow for **both** types — no forced full-payment rule.
- Cancellation: **no** relaxation — a `delivered` order stays closed for both types; a mis-rung instant
  sale is corrected via manual stock adjustment (FG-StockLog), not a new return/cancel path.
- `estimated_ready_at` is **required** at creation for `special`, guarded `after_or_equal:today`; freely
  editable afterward (owner may slip the date).
- Backfill: all historical orders default `fulfillment_type = special` (informational only — doesn't
  retroactively change any existing order's behavior).

### Interactions (verified orthogonal)
No conflict with **FT-OrderMoney** (discount/custom price) or **FG-OrderEdit** (stock/money
reconciliation) — `fulfillment_type` only touches the *initial status* and an *informational date*, not
pricing or stock math.

### Tests
`PhaseNNFulfillmentTest` — instant order created as `delivered` (stock/payment/discount still apply);
special order requires `estimated_ready_at`; special order follows the unchanged pending→ready→delivered
path; dashboard `dueToPrepare` picks up an overdue special order and excludes a not-yet-due one; edit
updates `estimated_ready_at` on an open order; tenant isolation. Existing `Phase14OrderEditTest` /
`Phase18OrderMoneyTest` should need no changes (purely additive).

**Impact analysis + full butterfly list:** [QA_TESTING_REPORT_3.md](QA_TESTING_REPORT_3.md) → Task 4.

---

*Update this tracker as each feature moves Planned → In progress → Done, mirroring the BUG_TRACKER
convention (status line + commit ref + test summary).*
