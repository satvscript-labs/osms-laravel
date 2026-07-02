# OSMS — QA / Requirements Analysis, Session 3

**Status:** 🟡 Living document — requirements are still being gathered from client discussions.
**Companion:** [BUG_TRACKER.md](BUG_TRACKER.md) · [QA_TESTING_REPORT_2.md](QA_TESTING_REPORT_2.md)
**Scope of this session:** three **feature requirements** (not bugs) that each ripple across the
system. This document is an *impact analysis* — for every requirement it records the current state
(grounded in source), the proposed change, a recommended approach, the **full list of affected
features (butterfly effect)**, data-model impact, edge cases, testing needs, and the **open decisions**
that must be settled before any code is written.

> ⚠️ **These are heavy, interdependent changes.** Task 1 rewires the core order relation, Task 2
> changes how money is calculated, and Task 3 changes what "selling price" *means* for every report.
> Nothing here should be built until the **Open decisions** at the end are answered — a wrong call on
> the data model will be expensive to unwind after data exists.

---

## Legend

- **Current** — how it works today, with file references.
- **Proposed** — what the requirement asks for.
- **Approach** — recommended design + alternatives considered.
- **Affected features** — the butterfly list: everything that must change or be re-verified.
- **Edge cases / risks** — what can break.
- **Tests** — new/updated coverage required.
- **Decisions** — questions that block implementation.

---

# Task 1 — Patients **and** Customers

## Current
- A single [`patients`](database/migrations/2026_01_01_000003_create_patients_table.php) table:
  `name`, `phone` (**NOT NULL**, `unique(tenant_id, phone)`), `age?`, `gender?`, soft-deletes.
- [`orders.patient_id`](database/migrations/2026_01_01_000006_create_orders_table.php#L14) is
  **NOT NULL** with a **cascade** FK to `patients`. **Every order must have a patient.**
- Eye records ([`eye_records`](database/migrations/2026_01_01_000004_create_eye_records_table.php))
  belong to a patient. Orders may attach one prescription (`eye_record_id`, nullable).
- The order builder ([orders/create.blade.php](resources/views/tenant/orders/create.blade.php))
  **requires** searching + selecting a patient before an order can be placed.
- Patients surface in: [SearchController](app/Http/Controllers/Tenant/SearchController.php) (Cmd+K),
  [AnalyticsController](app/Http/Controllers/Tenant/AnalyticsController.php) (ledger + dues),
  [DashboardController](app/Http/Controllers/Tenant/DashboardController.php) (overdue pickups),
  the [receipt PDF](resources/views/tenant/orders/receipt-pdf.blade.php), and
  [PatientsExport](app/Exports/PatientsExport.php).

## Proposed
Introduce **customers**: buyers of sunglasses/accessories who don't need a clinical record. A
**patient is a customer**, but a **customer is not always a patient**. Orders must be placeable for a
plain customer (no prescription), and the order builder needs a "more robust and compatible" way to
pick or quickly add a customer.

## Approach — recommended: **unify into one contact entity**
Model reality with a **single `customers` table** where "patient" is a *derived role*, not a separate
record. A customer becomes a "patient" the moment they have ≥ 1 eye record (or an explicit
`is_patient` flag for stores that want to mark it manually).

- **Why unify (vs. two separate tables):** the same person often is both. Two tables cause duplicate
  contacts, split history, ambiguous search ("which table?"), and double the CRUD/exports/tests. One
  contact entity is the standard CRM/optical-POS model and keeps order history in one place.
- **Migration path:** rename `patients` → `customers` (data-preserving) **or** create `customers` and
  copy rows. Existing patients migrate as customers with `is_patient = true`. `orders.patient_id`
  becomes `customer_id`; `eye_records.patient_id` becomes `customer_id`.
- **Walk-in / anonymous:** relax `phone` to **nullable** (walk-ins may not share one). Keep uniqueness
  only when a phone is present (`unique(tenant_id, phone)` tolerates multiple NULLs on MySQL; enforce a
  partial/guarded unique). Optionally allow a nameless "Walk-in" quick sale.
- **UI:** the order builder gets a combined "search existing **or** add new customer inline" control
  (name + phone). A "Patients" filter/tab = customers who have prescriptions; "Add prescription"
  **promotes** a customer to a patient.
- **✅ DECIDED — inline auto-register on order creation:** the user must **never** have to register a
  customer as a separate step before an order. In the order builder, if the searched customer isn't
  found, the staffer just types **name + phone** and the customer is **created automatically on order
  submit** (find-or-create by `(tenant_id, phone)` — an existing phone reuses that customer, so no
  duplicates). This is a first-class part of the store/update flow, not just a convenience.

**Alternatives considered:** (B) separate `customers` table with two nullable FKs on orders — rejected
(dual search, duplicate people, 2× surface). (C) keep `patients` name, just relax fields — minimal
churn but leaves confusing domain language and doesn't model "customer" as first-class.

## Affected features (butterfly)
**Database & models**
- `patients` → `customers` table (rename/repurpose); `phone` nullable + guarded unique; keep
  `tenant_id`, soft-deletes, `HasUuid`. Add `is_patient` (or derive from eye-record count).
- `orders.patient_id` → `customer_id` (FK + index + cascade). **NOT NULL stays** (every order still
  has a contact) unless anonymous walk-ins are allowed → then nullable.
- `eye_records.patient_id` → `customer_id`.
- `Patient` model → `Customer` (relations `eyeRecords`, `orders`; `BelongsToTenant`, `SoftDeletes`).
- `Order`: `patient()` → `customer()`, fillable, store/edit validation keys.
- `EyeRecord`: `patient()` → `customer()`.

**Controllers & routes**
- `PatientController` → `CustomerController` (index/create/store/show/edit/update/trash/destroy/
  restore/forceDelete/export). Route names `patients.*` → `customers.*` (**breaks every `route()` /
  `safe_route()` call** referencing them — must sweep all views).
- `OrderController`: `create`/`store`/`edit`/`update` (customer picker + inline quick-add),
  `eyeRecords` endpoint (patient → customer).
- `EyeRecordController`: nested under customer; "add prescription" promotes to patient.
- `SearchController`: `patients` result key → `customers`.

**Views**
- `patients/*` → `customers/*` (index, show, create, edit, trash, `_form`).
- `orders/create` + `orders/edit`: new combined picker + inline add.
- `receipt-pdf`: "Patient" block → "Customer"; hide the Rx box when none.
- `dashboard` (overdue pickups name), `analytics` (ledger/dues name), Cmd+K global search,
  `eye-record-card` links, **sidebar nav** ("Patients" → "Customers", or both).
- `PatientsExport` → `CustomersExport` (+ `is_patient` / has-Rx column).

**Tests & fixtures**
- `PatientFactory` → `CustomerFactory`; **every test** that creates a patient or posts `patient_id`
  (Phase11/12/14 + earlier) must be updated. Large but mechanical churn.

## Edge cases / risks
- **Backfill:** existing patients → customers with `is_patient=true`; existing orders' `patient_id`
  values must map 1:1 to `customer_id`. Must be a single reversible migration with data copy.
- **Phone uniqueness with NULLs:** ensure the unique index tolerates multiple null phones but still
  blocks duplicate real phones per tenant.
- **Soft-delete guard (C1):** the "can't archive a customer with orders" rule carries over.
- **Cross-tenant isolation** must hold on the renamed entity (re-run isolation tests).
- **Route renames** are the silent-break risk — a missed `route('tenant.patients.*')` 500s at runtime,
  not compile time. Grep-sweep + a smoke test hitting every tenant route.

## Tests
- Customer CRUD + tenant isolation; walk-in quick-add (with/without phone); promote-to-patient via
  eye record; order for a plain customer (no Rx); order for a patient (with Rx); backfill migration
  correctness (orders keep their contact); global search returns customers; export includes type.

## Decisions (blocking) → see consolidated list at end: **D1–D4**

---

# Task 2 — Create Order enhancements

## Current
- [`Order`](app/Models/Order.php): `total_amount`, `advance_paid`, `balance_due` (hook:
  `balance_due = total_amount − advance_paid`). **No discount concept.**
- [`OrderItem`](app/Models/OrderItem.php): `unit_price` captured at creation.
- [`store()`](app/Http/Controllers/Tenant/OrderController.php#L104) resolves each line price
  **server-side** from `selling_price` ("never trust the client") and hardcodes the initial advance
  as a **cash** `Payment`.
- [`recordPayment()`](app/Http/Controllers/Tenant/OrderController.php) already captures a `method`
  (`cash|card|upi|other`); [`Payment.method`](app/Models/Payment.php) + `method_label` exist.
- Receipt PDF totals block: Subtotal (= `total_amount`), Advance, Balance — **no discount, no payment
  method**.

## Proposed
1. **2.1.1 Discount** — order-level discount as **percent or amount**, with live calculation.
2. **2.1.2 Custom selling price** per line item (override the list price) — premium, non-cluttered UI.
3. **2.2 Advance payment method** — capture method on the initial advance; surface it in receipts and
   reports wherever useful.
4. **2.3 Redesign** the create-order page to absorb Task 1 (customer picker) + these additions without
   feeling cluttered, keeping the premium design system.

## Approach
**Discount (2.1.1)** — make the money model explicit so history and analytics stay correct:
- Add to `orders`: `subtotal` (sum of line `unit_price × qty`), `discount_type`
  (`none|percent|amount`), `discount_value` (what the user entered), `discount_amount` (resolved ₹).
- Redefine `total_amount = subtotal − discount_amount`. The `balance_due` hook is **unchanged**
  (`total_amount − advance_paid`).
- Alpine live-calc (toggle % / ₹); **server recomputes authoritatively** and clamps:
  `0 ≤ discount_amount ≤ subtotal`, percent `0–100`, `advance ≤ total`.

**Custom selling price (2.1.2)** — the builder may edit a line's `unit_price`; already persisted per
line. Server validates: `numeric`, `≥ 0`, capped to avoid `decimal(10,2)` overflow. Below cost →
**allow-but-warn** (consistent with [NB-004](BUG_TRACKER.md)). **Recommended:** also snapshot the
list price per line (`order_items.list_price`) so reports can show *per-item discount* (list vs sold).

**Advance payment method (2.2)** — add a method dropdown to the advance control; pass it to the initial
`Payment` (drop the hardcoded cash). Surface method on the receipt (advance line) and in payment
history (already shows `method_label`). Optional: a "payments by method" analytics breakdown.

**Redesign (2.3)** — keep the two-column builder. Inline-edit a line's unit price (click the price);
put the discount control (compact % / ₹ toggle) and the advance amount + method in the summary card.
Everything uses design tokens; no new hardcoded styles.

## Affected features (butterfly)
**Database & models**
- `orders`: + `subtotal`, `discount_type`, `discount_value`, `discount_amount`. `order_items`: +
  `list_price` (optional, recommended). Migration must be **portable** (SQLite dev / MySQL prod) and
  **backward-compatible** (existing orders: `discount_* = 0/none`, `subtotal = total_amount`).
- `Order` model: fillable + casts; discount label helper.

**Controllers**
- `store()`: accept discount + per-line `unit_price` (+ method); recompute subtotal/discount/total;
  clamp + validate; create initial `Payment` with chosen method.
- `update()` (**C3 edit**): must re-reconcile **with** discount + custom prices. Interacts with the
  existing rule "existing line keeps captured price" — custom prices must be preserved on edit; the
  discount must be preserved/editable. **This is the trickiest integration point.**

**Views**
- `orders/create` + `orders/edit` Alpine builders: discount toggle, inline price edit, advance method,
  live totals.
- `receipt-pdf`: add Subtotal → Discount → Total rows; show advance **method**.
- `orders/show`: show discount + payment method(s) (history already shows method).

**Analytics — the high-risk butterfly** ⚠️
- [`AnalyticsController`](app/Http/Controllers/Tenant/AnalyticsController.php): `revenue` currently
  `= sum(total_amount)`. With discounts, `total_amount` is **net of discount** → revenue becomes net
  (correct, standard). **BUT** `topBrands` revenue sums `unit_price × qty` (**gross** of the
  order-level discount) → brand revenue will **not** reconcile with total revenue. Must decide:
  allocate the order discount **pro-rata to lines** for brand revenue (recommended, keeps numbers
  consistent) or report brand revenue as gross-with-a-note.
- `profit = revenue − COGS`, `margin` — both now reflect real discounts/custom prices. Custom price
  below cost → **negative line margin**; reports must render negatives gracefully.
- Consider a new KPI: **total discounts given** in range.
- `LedgerExport` / analytics ledger: optionally add a Discount column.
- `DashboardController.todaySales` uses `total_amount` (net) — fine, but "sales" now means net.

**Tests**
- store/edit with discount (percent + amount, clamps), custom price (incl. below-cost warn),
  advance method persisted, receipt renders discount + method, analytics revenue = net,
  brand-revenue reconciliation, backward-compat (no discount → old totals). Update Phase11/Phase14
  where totals are asserted.

## Edge cases / risks
- Discount > subtotal, negative discount, percent > 100 → clamp server-side.
- Custom price 0 (free item) vs negative (reject); overflow guard.
- Discount + custom price + **order edit** all interacting (C3) — reconciliation math must stay atomic.
- Advance ≤ total *after* discount (not before).
- Rounding: percent discounts produce fractions — define rounding (2 dp) consistently across UI,
  server, PDF, analytics to avoid ±₹0.01 drift.
- Analytics reconciliation (brand vs total) — the flagged complexity.

## Decisions (blocking): **D5–D9**

---

# Task 3 — Inventory

## Task 3.1 — Cost price / Selling price semantics
### Current
- [`Inventory`](app/Models/Inventory.php): `cost_price`, `selling_price` (both `decimal(10,2)`).
- Analytics COGS uses `cost_price × qty`; brand revenue uses the order line's `unit_price`
  ([AnalyticsController](app/Http/Controllers/Tenant/AnalyticsController.php#L44-L67)).

### Proposed
Keep both fields, but acknowledge **`selling_price` is no longer absolute** — Task 2's custom price
means the actual sale price lives on the order line. Reports get more complex; handle with care.

### Approach
- Reframe `selling_price` as the **default / list price (MRP)**. Actual revenue always comes from the
  order line's captured `unit_price` — which the analytics revenue path effectively already uses. So
  **actual** margin/profit stay correct.
- The new nuance is **expected vs actual**: "expected margin" (from list `selling_price`) vs "actual
  margin" (from sold `unit_price`). If the store wants discount reporting, keep the per-line
  `list_price` snapshot (see Task 2) and report *discount given* = `Σ(list_price − unit_price) × qty` +
  order-level discount.
- **Audit for the assumption `selling_price == sale price`:** analytics already uses `unit_price`
  (safe); inventory list shows list price (fine); exports show list price (fine). Document the meaning
  so future reports don't reintroduce the wrong assumption.

### Affected features (butterfly)
- Analytics (shared with Task 2): actual vs expected margin; negative-margin rendering.
- Inventory index/edit labels: clarify "Selling price" = default/list price (copy tweak).
- Exports (`InventoryExport`): unchanged columns, but the semantic is "list price".
- Any **future** report must use the order line price for revenue, never `selling_price`.

### Tests
- Analytics with a custom sale price ≠ list price → revenue/margin use the sold price; discount-given
  metric (if added) computes from `list_price`.

## Task 3.2 — Printable / downloadable barcode label
### Current
- Barcodes are random 12-digit, unique per tenant ([BUG-004](BUG_TRACKER.md)); scan lookup matches
  `barcode` **or** `sku` ([InventoryController::scan](app/Http/Controllers/Tenant/InventoryController.php)).
- [`JsBarcode`](resources/js/app.js) (Code128, client-side) is **already bundled**; `@media print`
  rules already exist in [app.scss](resources/sass/app.scss).

### Proposed
On **Edit item**, add **Print** and **Download** for a **thin barcode label** for store tagging. The
default file name must be the **SKU**.

### Approach — client-side, no new dependency
- Render the item's `barcode` as **Code128** with JsBarcode into an SVG/canvas in a compact,
  print-friendly label (barcode + human-readable SKU; optionally brand/model/price).
- **Download** = canvas → PNG → save as `{SKU}.png`. **Print** = open a print window / print-CSS
  section sized for a thin label. Fully offline, matches the existing scan symbology.
- Keep it a small addition to the inventory edit page (a "Barcode" panel/modal).

### Affected features (butterfly) — **low, mostly additive**
- `inventory/edit` view (Barcode panel + print layout), a small JS helper, minor print-CSS.
- Ensure rendered Code128 of the `barcode` value round-trips through `scan()` (scannable + resolvable).
- Filename convention (SKU) — sanitize SKU for filesystem safety.

### Edge cases / risks
- SKU characters unsafe for filenames → sanitize.
- Label DPI/size vs the store's label printer — provide a sensible default; make size a constant.
- Very long brand/model text overflowing a thin label → truncate.

### Tests
- Mostly front-end/manual (JsBarcode render). Assert the edit page exposes the barcode panel and the
  data attributes (SKU/barcode) are present; scan-lookup regression stays green.

---

# Task 4 — Instant vs. special-order fulfillment

## Current
- [`Order.status`](database/migrations/2026_07_01_000001_add_cancel_to_orders.php) is one of
  `pending | ready_for_pickup | delivered | cancelled`. **Every** order is created as `pending`
  ([OrderController::store()](app/Http/Controllers/Tenant/OrderController.php)), regardless of whether
  the customer is taking the item immediately or it needs lab prep.
- The kanban board ([orders/partials/kanban.blade.php](resources/views/tenant/orders/partials/kanban.blade.php))
  labels the `pending` column **"In lab"** — the concept of a prep stage already exists in the UI, but
  there is no estimated-completion date anywhere in the schema.
- [`DashboardController`](app/Http/Controllers/Tenant/DashboardController.php) has one time-based alert:
  `overduePickups` — `ready_for_pickup` orders sitting **uncollected** > 3 days (nothing tracks whether
  an **in-lab** order is behind its promised date, because no such date exists).
- [NB-009 cancel](app/Http/Controllers/Tenant/OrderController.php) blocks cancelling *any* `delivered`
  order, with no distinction by how the order reached that status.

## Proposed
Most of this store's customers fall into two very different fulfillment patterns: (1) **grab an
in-stock item, pay, and leave** (sunglasses, accessories, frames with no lens work) — there is nothing
to track, the sale is complete on the spot; (2) **a special/prepared order** (custom lens grinding,
fitting, anything needing shop time) — the owner gives the customer an **estimated ready date**, and
the order genuinely needs to move through a prep → ready → collected pipeline. The order builder should
fast-track case (1) instead of forcing every walk-in sale through the same multi-stage workflow as a
lab job.

## Approach
- **New column `orders.fulfillment_type`**: `instant | special`. Chosen via a segmented toggle at the
  top of the order builder — **"Take today"** vs **"Special order (needs prep)"**.
- **`instant`** → the order is created **already `delivered`** (skips `pending`/`ready_for_pickup`
  entirely — nothing to prepare, nothing to wait on). Stock decrements, payment records, discount/custom
  price all work exactly as today; only the initial `status` differs.
- **`special`** → unchanged `pending` start, **plus a new required `estimated_ready_at` date** (a date
  picker, default **today + 3 days**, adjustable). This flows through the existing
  `pending` ("In lab") → `ready_for_pickup` → `delivered` kanban pipeline unchanged.
- **Payments:** unchanged for both types — the existing flexible advance/balance flow applies regardless
  of `fulfillment_type` (D-payment, answered: no forced full-payment rule).
- **Cancellation:** unchanged — a `delivered` order stays closed for **both** types (D-instant-cancel,
  answered: no special-case relaxation of NB-009). A mis-rung instant sale is corrected via the existing
  [FG-StockLog manual adjustment](BUG_TRACKER.md#fg-stocklog-no-manual-stock-adjustment-audit), same as
  any other stock correction today — **not** a new returns/refund flow (explicitly out of scope for v1).
- **Dashboard — new "Due to prepare" alert**: `pending` + `special` orders whose `estimated_ready_at` is
  today or already past, surfaced alongside the existing "uncollected > 3 days" alert in the same Alerts
  panel (distinct icon/copy: *"Due today"* / *"N days overdue to prepare"*).
- **Kanban card**: the `pending` ("In lab") column's cards show the estimated ready date (and flip to a
  red "overdue" tone once past), so staff can prioritize by the promise date without opening the alerts
  panel.
- **Order edit** ([FG-OrderEdit](BUG_TRACKER.md#fg-orderedit-orders-are-immutable-after-creation)):
  `estimated_ready_at` becomes an editable field on `update()` for still-open (`pending`/
  `ready_for_pickup`) orders — the owner can push the promise date without a new route. `instant` orders
  are created already `delivered`, so they're immediately outside the existing "only open orders are
  editable" guard — no code change needed there, it falls out naturally.
- **Receipt / order show**: a special order's receipt/detail page shows *"Estimated ready: `DATE`"*;
  an instant sale shows no such line (it's already complete).

## Affected features (butterfly)
**Database & models**
- `orders`: + `fulfillment_type` (string, default `special` for backward-compat backfill — see below) and
  `estimated_ready_at` (nullable `date`). Portable migration (plain `ALTER TABLE ADD COLUMN`, no enum
  widening needed — no MySQL/SQLite branching required this time).
- `Order` model: fillable + casts (`estimated_ready_at` → `date`); helper `isInstant()` /
  `needsPrep()`; a `getFulfillmentLabelAttribute()` for display.

**Controllers**
- `OrderController::store()`: accept `fulfillment_type` (required, `in:instant,special`); when
  `special`, require `estimated_ready_at` (`date`, `after_or_equal:today`); set `status = 'delivered'`
  immediately for `instant` (bypassing the normal `pending` default) — the rest of `store()` (stock,
  discount, custom price, payment) is **unchanged** and applies identically to both types.
  `update()`: accept an optional `estimated_ready_at` update for open orders.
- `DashboardController`: new `dueToPrepare` query (`pending` + `special` + `estimated_ready_at <=
  today`), same shape as the existing `overduePickups` mapping.

**Views**
- `orders/create.blade.php`: new fulfillment-type toggle (Alpine state) near the top of the form;
  conditional date picker for `special`; submit button label could reflect the choice (*"Complete
  sale"* vs *"Create order"*) — cosmetic, decide during build.
- `orders/edit.blade.php`: editable estimated-ready-date field when the order is `special` and still
  open.
- `orders/show.blade.php` + `receipt-pdf.blade.php`: estimated-ready line for `special` orders.
- `orders/partials/kanban.blade.php`: due-date badge (+ overdue tone) on `pending`-column cards.
- `dashboard.blade.php`: new alert-list entries for `dueToPrepare`, interleaved with the existing
  `overduePickups` source in the same Alerts panel.

**Tests**
- `PhaseNNFulfillmentTest` — instant order created as `delivered` (stock/payment/discount all still
  apply); special order requires `estimated_ready_at`; special order follows the unchanged
  pending→ready→delivered path; dashboard `dueToPrepare` picks up an overdue special order and excludes
  a not-yet-due one; edit updates `estimated_ready_at` on an open order; tenant isolation on the new
  query. Existing `Phase14OrderEditTest` / `Phase18OrderMoneyTest` suites should need **no changes** —
  this is additive to `store()`/`update()`, not a rework of their money/stock logic.

## Edge cases / risks
- **Backward compatibility**: existing rows have no `fulfillment_type`. Backfilling `special` for all
  historical orders is the safe default — it doesn't retroactively claim any old order was an "instant"
  sale, and since `fulfillment_type` only drives *new-order* behavior (initial status + date
  requirement), a historical `delivered` order is unaffected either way.
- **`estimated_ready_at` in the past**: guard with `after_or_equal:today` at creation; editing later
  (e.g. the owner slipping the date) should probably still allow moving it earlier or later freely once
  set — the guard is only for the initial promise.
- **Instant sale return gap (flagged, decided)**: with `instant` orders landing on `delivered`
  immediately, there is now **no cancel path** for a mis-rung counter sale (decided: acceptable for v1,
  corrected via manual stock adjustment instead — see Approach above). Worth revisiting if returns
  become a common real-world pain point.
- **Kanban "In lab" column will only ever contain `special` orders** going forward (instant orders never
  enter `pending`) — this is intentional, not a bug, but worth a one-line note in the column
  description/copy so it doesn't read as "broken" once instant sales stop appearing there.
- Interaction with **FT-OrderMoney** (discount/custom price) and **FG-OrderEdit** (stock/money
  reconciliation) is orthogonal — `fulfillment_type` only touches the *initial status* and an
  *informational date*, not pricing or stock math. No cross-feature conflict expected.

## Tests
See "Affected features → Tests" above.

## Decisions

### ✅ Answered (2026-07-02)
- **D-status** — Instant sale skips straight to `delivered` at creation (never touches
  `pending`/`ready_for_pickup`).
- **D-date** — `estimated_ready_at` is **required** when `fulfillment_type = special`.
- **D-payment** — Keep the existing flexible advance/balance flow for **both** types; no forced
  full-payment rule for instant sales.
- **D-dashboard** — Add a new **"Due to prepare"** dashboard alert (not just a passive date on the
  card).
- **D-instant-cancel** — **No** special-case cancel relaxation: a `delivered` order stays closed for
  both `instant` and `special` — a wrong instant sale is corrected via manual stock adjustment, not a
  new cancel/return path.

---

# Consolidated butterfly matrix

| Module / file | Task 1 (Customers) | Task 2 (Order+Discount+Price+Method) | Task 3.1 (Pricing semantics) | Task 3.2 (Barcode) | Task 4 (Fulfillment) |
|---|:--:|:--:|:--:|:--:|:--:|
| `orders` migration/model | ● rename FK | ● discount/subtotal cols | — | — | ● fulfillment_type + date |
| `order_items` model | — | ● custom price (+list_price) | ○ meaning | — | — |
| `patients`→`customers` | ● core | — | — | — | — |
| `eye_records` | ● FK rename | — | — | — | — |
| OrderController store/edit | ● picker | ● discount/price/method | ○ | — | ● status + date logic |
| Order builder (create/edit) | ● picker | ● UI redesign | — | — | ● fulfillment toggle |
| Receipt PDF | ● label | ● discount+method rows | — | — | ○ ready-date line |
| AnalyticsController | ○ name | ● revenue/brand reconcile | ● actual vs expected | — | — |
| Ledger/Exports | ○ name | ○ discount col | ○ | — | — |
| Dashboard | ○ name | ○ net sales | — | — | ● due-to-prepare alert |
| Global search | ● customers | — | — | — | — |
| Inventory edit view | — | — | ○ copy | ● barcode panel | — |
| Kanban board | — | — | — | — | ○ due-date badge |
| Sidebar / nav | ● rename | — | — | — | — |
| Factories / all tests | ● large | ● totals | ○ | ○ | ○ additive |

● = direct change · ○ = re-verify / minor · — = none

---

# Suggested sequencing (once decisions are locked)
1. **Task 1 (Customers)** — foundational; rewires the order relation. Do first, with a data-preserving
   migration and a full test/fixtures sweep. Highest churn, lowest math risk.
2. **Task 2 (Order enhancements)** — builds on the new picker; introduces the money-model changes.
   Do second; treat the **analytics reconciliation** as its own hardening step.
3. **Task 3.1 (Pricing semantics)** — largely *realised* by Task 2; mostly analytics reconciliation +
   documentation + copy. Fold into Task 2's analytics work.
4. **Task 3.2 (Barcode)** — independent, additive, low-risk. Can ship anytime as a quick win (even
   before 1–3) since it touches nothing the others touch.
5. **Task 4 (Fulfillment)** — independent of Tasks 1–3's data model (only touches `orders` additively);
   can be built anytime after Task 1 (needs the `customers` rename in place, since it edits the same
   `store()`/builder). Low risk — additive columns, no money/stock math changes.

---

# Open decisions

## ✅ Answered (2026-07-01)
- **D1 — Model:** **Unify** into one `Customer` entity ("patient" = a customer with a prescription).
  **Plus:** inline **auto-register on order creation** — no separate registration step (find-or-create
  by `(tenant_id, phone)`).
- **D2 — Walk-ins:** **Name + phone stay required** (no anonymous orders). Inline quick-add still
  captures both, so `customer_id` stays **NOT NULL** and `phone` stays **NOT NULL + unique per tenant**
  (simpler than the nullable path — the walk-in speed comes from inline create, not from dropping
  fields).
- **D6 — Permissions:** **All staff** may apply discounts / custom prices (no role gate).
- **D7 — Analytics:** **Net revenue** (after discount) **+ allocate order-level discount pro-rata to
  lines** so brand revenue reconciles with total revenue.

## 🟠 Proposed defaults (confirm, else I proceed as written)
- **D3 — Nav:** one **"Customers"** section; a **"Patients" filter** within it (customers who have ≥ 1
  prescription). *Default: proceed.*
- **D4 — Routes/tables:** **rename** `patients.*` → `customers.*` and `patients` → `customers` now
  (pre-launch, cleaner; full grep-sweep + smoke test). *Default: proceed.*
- **D5 — Discount scope:** **order-level** discount + **per-line custom price** (covers per-item
  needs). *Default: proceed.*
- **D8 — Snapshot `list_price`:** **yes**, store per-line list price for discount reporting. *Default:
  proceed.*
- **D9 — Below cost:** **allow-but-warn** (consistent with NB-004). *Default: proceed.*
- **D10 — Pricing semantics:** `selling_price` = **default/list (MRP)**; revenue always reads the order
  line price. *Default: proceed.*
- **D11 — Barcode:** **Print + Download PNG**, filename = **SKU**, label shows **barcode + SKU +
  brand/model** at a sensible default thin-label size. *Default: proceed.*

---

*Next step: this document's per-task sections are now the build spec. Task 3.2 (barcode) is independent
and can ship first as a low-risk quick win. Task 1 → Task 2 (with 3.1 folded in) follow. Each task
gets a `PhaseNN…Test` suite with tenant-isolation coverage per the project testing rules.*
