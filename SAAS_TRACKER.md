# OSMS Laravel — SaaS Launch Tracker

**Companion:** [SAAS_LAUNCH_REPORT.md](SAAS_LAUNCH_REPORT.md) (full impact analysis) ·
[FEATURE_TRACKER.md](FEATURE_TRACKER.md) (domain features — all ✅ Done) · [BUG_TRACKER.md](BUG_TRACKER.md) · [SAAS_Payment_Prompt.md](SAAS_Payment_Prompt.md).
**Scope:** the **SaaS business layer** — everything that turns the finished optical app into a
self-serve, paid, multi-tenant product. This is the **build tracker** (what we build, in what order,
grouped into parallel bunches); the deep per-item analysis lives in
[SAAS_LAUNCH_REPORT.md](SAAS_LAUNCH_REPORT.md).

> **Launch goal (owner):** secure the **first ~5 paying stores fully automatically** — a store signs up,
> trials, pays online, and uses the app **without ever contacting us**. No manual provisioning, no
> manual billing. Standard small-cap approach; ship lean, correct, and automated.

> Every item follows `CLAUDE.md` [VISUAL DESIGN SYSTEM DIRECTIVE] (premium iOS-inspired UI, design
> tokens only, spring-eased motion) and ships with a `PhaseNN…Test` suite carrying a tenant-isolation
> assertion for every new tenant-owned action.

---

## Locked decisions (SaaS launch — 2026-07-03)

Settled with the product owner:

- **Single plan at launch — no tiers.** Only the **Basic** plan exists; **no tier gating, no
  per-feature locks** — every paying store gets the full app. This collapses report Task **S2** to a
  single concern (a staff cap) and removes upgrade/downgrade from **S4** and the plan-picker from **S7**.
- **Global staff cap (cost control).** A single configurable cap on users-per-store guards our cost
  (each staff seat = more usage). Default **`saas.max_staff = 5`** total users per tenant (owner + 4).
  🟠 *Confirm-to-change — one config line.* Over-cap → friendly "seat limit reached" message, not a 500.
- **Payments are in scope for this update.** Razorpay goes **live** as part of this work — the automated
  purchase path is the whole point. (External dependency: a live Razorpay merchant account, which
  itself requires the **S6 legal pages** + business/GST identity — see risks.)
- **Automated, self-serve everything** — signup → 14-day trial → online payment → active, with all
  lifecycle emails automated. No human in the loop.

### Standard defaults adopted (report "Open decisions", small-cap posture)
- **D-S1a** Locked workspace = **hard-lock** (redirect all module routes to billing). No read-only mode.
- **D-S1b** Grace window for `past_due` = **7 days**.
- **D-S1c** Trial/period expiry = **end-of-day, app timezone (IST)**.
- **D-S2b** Trial = full app (only one plan exists anyway).
- **D-S5a** Production mail = **Hostinger SMTP** (cheapest standard path; swap to a provider later if
  deliverability needs it).
- **D-S5b** Enable `verified` email at launch, **after** SMTP is confirmed in staging.
- **D-S8a** Queue driver = **database** (no Redis for small-cap).
- **D-S8b/c** Daily off-box **DB + uploads backup**; **Sentry free tier** for error monitoring.
- **D-S10a** Last store-admin **cannot self-delete**; closing a store cancels the subscription first.
- **D-S10b** Post-close retention = **30 days** (matches the FG-Delete purge window).

### 🟠 Owner still to provide (does not block starting the build)
- **Legal copy** (Terms / Privacy / Refund & Cancellation / Contact) and the **business + GST
  identity** named in those docs and on invoices (**D-S6a/c**). We build the pages/routes now with a
  clearly-marked placeholder; real copy drops in before go-live.
- **Live Razorpay keys + a single "Basic" plan_id** (needed only to flip payments on; the code lands
  behind `BillingService::isConfigured()` and works in test mode meanwhile).

---

## Build order & parallel bunches

Nine items, grouped into **five bunches** to distribute workload. Items **inside** a bunch share
surface area (do them together to avoid rework); bunches are ordered by dependency, but **Bunch 2 runs
fully in parallel with Bunch 1**.

| Bunch | # | Ref | Item (report task) | Depends on | Priority | Status |
|:--:|:--:|---|---|---|:--:|:--:|
| **1 · Foundation** | 1 | ST-Enforce | Subscription lifecycle & access enforcement (S1) | — | 🔴 | ✅ Done |
| **1 · Foundation** | 2 | ST-Infra | Queue + scheduler + branded error pages + `APP_DEBUG=false` (S8a) | — | 🔴 | ✅ Done |
| **2 · Comms & compliance** | 3 | ST-Email | Transactional email, `verified`, queued lifecycle mailables (S5) | ST-Infra (queue) | 🔴 | ⬜ Planned |
| **2 · Comms & compliance** | 4 | ST-Legal | Legal & compliance pages + landing footer links (S6) | — | 🔴 | ⬜ Planned |
| **3 · Money path** | 5 | ST-Billing | Single-plan subscribe / cancel / invoices + Razorpay go-live + webhook hardening (S4) | ST-Enforce, ST-Email, ST-Legal | 🔴 | ⬜ Planned |
| **4 · People & data** | 6 | ST-Staff | Staff management + global staff cap (S3 + S2-lite) | ST-Email | 🟠 | ⬜ Planned |
| **4 · People & data** | 7 | ST-Lifecycle | Account/tenant data lifecycle (last-admin guard, close-store, export) (S10) | ST-Billing (cancel-on-close) | 🟠 | ⬜ Planned |
| **5 · Polish & harden** | 8 | ST-Onboard | Trial messaging + empty states (S7-lite, no plan picker) | ST-Enforce | 🟡 | ⬜ Planned |
| **5 · Polish & harden** | 9 | ST-Harden | Backups + monitoring + restore drill (S8b) | ST-Infra | 🟡 | ⬜ Planned |

Status legend: ⬜ Planned · 🟨 In progress · ✅ Done. Priority: 🔴 launch-blocker · 🟠 launch-important · 🟡 fast-follow.

### Dependency graph (critical path in bold)

```
Bunch 1  ST-Infra ─────────────┐
         **ST-Enforce**        │
              │                 ├──► **ST-Billing** ──► ST-Lifecycle
Bunch 2  **ST-Email** ─────────┤        (Bunch 3)        (Bunch 4)
         **ST-Legal** ─────────┘
         ST-Staff  ◄── ST-Email
         ST-Onboard ◄── ST-Enforce        ST-Harden ◄── ST-Infra   (Bunch 5)
```

**Critical path:** ST-Enforce + ST-Infra + ST-Email + ST-Legal → **ST-Billing**. Everything a store
touches to *pay us* sits on that chain. ST-Staff, ST-Lifecycle, ST-Onboard, ST-Harden hang off it and
can trail.

---

## Bunch 1 · Foundation

### ST-Enforce — Subscription lifecycle & access enforcement (S1) 🔴
- **Status:** ✅ Done (2026-07-03). **Depends on:** — (daily reconcile needs ST-Infra's scheduler).

#### Shipped

- **`Subscription::accessState()`** state machine (`active|grace|locked`) + `hasAccess()`,
  `isInGracePeriod()`, `trialDaysLeft()`. Derived live from `status` + `current_period_end`; expiry is
  end-of-day in `config('billing.timezone')` (IST), grace = `config('billing.grace_days')` (7). A paid
  `active` sub past its period gets grace (late-renewal-webhook tolerance); a `trialing` sub never does.
- **`Tenant::hasActiveAccess()`** + a **model invariant**: `Tenant::created` auto-starts a 14-day trial
  subscription, so every store (onboarding / seeder / tests) always has a row — a missing subscription
  can never silently mean "free forever". OnboardingController + seeder simplified to rely on it.
- **`EnsureSubscriptionActive` middleware** (alias `subscribed`) on the tenant group; `locked` →
  hard-redirect to billing with a tailored message; `tenant.billing.*` self-exempt; superadmins bypass;
  no subscription row → locked.
- **Trial / grace banner** (`partials/subscription-banner.blade.php`) in the app layout (design-system
  alert styling, dismissible trial countdown / persistent grace warning).
- **`subscriptions:reconcile`** command (scheduled 02:15 daily) flips expired trials to `canceled`.
- **Tests:** `Phase22SubscriptionAccessTest` (15) — accessState unit matrix, middleware lock/allow per
  state, no-subscription lock, billing reachable while locked, cross-tenant isolation, reconcile
  flips-expired-spares-live. Full suite **208 passed**.

#### Original plan

- **Why first:** today **nothing checks the subscription** — a store uses the app free forever
  ([SAAS_LAUNCH_REPORT.md](SAAS_LAUNCH_REPORT.md) → S1). This is the keystone; without it there is no
  business.
- **Scope**
  - `Subscription::accessState(): 'active'|'grace'|'locked'` (+ `trialDaysLeft()`, `isInGracePeriod()`),
    reading `status` + `current_period_end` with the 7-day grace and IST end-of-day rules.
  - `Tenant::hasActiveAccess()` (a tenant with **no** subscription row → `locked`, never open).
  - New middleware `EnsureSubscriptionActive` (alias `subscribed`) applied to the tenant group in
    [routes/web.php](routes/web.php), with `billing.*` / `profile.*` / `logout` **exempt** so a locked
    store can still reach the pay page.
  - `hard-lock` on `locked` (redirect → `tenant.billing.index` with a blocking banner); non-blocking
    warning banner on `grace`; trial-countdown banner in [layouts/app.blade.php](resources/views/layouts/app.blade.php).
  - `subscriptions:reconcile` console command (daily) — flips expired `trialing`/`active` rows to a
    terminal state and (via ST-Email) fires trial-ending / payment-failed mail.
- **Build sequence:** (a) model state machine + tests → (b) middleware + route wiring + exemptions →
  (c) banners → (d) reconcile command + schedule entry.
- **Tests** `PhaseNNSubscriptionAccessTest` — locked when trial expired / canceled / past-due-beyond-grace;
  allowed during grace (with warning) and when active/trialing in-window; billing reachable while
  locked; superadmin bypass; reconcile flips an expired trial and spares a live one; tenant isolation.

### ST-Infra — Queue + scheduler + error pages + debug (S8a) 🔴
- **Status:** ✅ Done (2026-07-03). **Depends on:** —.

#### Shipped

- **Queue** already on the `database` driver (`config/queue.php` + jobs-table migration present) —
  verified, no change needed; worker documented in the README runbook.
- **Branded error pages** `resources/views/errors/{403,404,419,500,503}.blade.php` on a shared
  self-contained `errors/layout.blade.php` (spotlight + glass, design tokens, no app-layout dependency
  so a 500 always renders).
- **README "Production deploy & operations"** runbook: `migrate --force` → `optimize` →
  `storage:link`, `APP_DEBUG=false`, the **required `schedule:run` cron** (drives
  `subscriptions:reconcile` + `model:purge-trashed`), and the `queue:work` worker.
- **Remaining for ST-Harden (Bunch 5):** backups, Sentry, restore drill, HTTPS/HSTS headers.

#### Original plan

- **Why (S8a):** ST-Enforce's reconcile and ST-Email both need a **running scheduler + queue worker**; without
  the cron, trials never expire and the existing `model:purge-trashed` also silently no-ops.
- **Scope**
  - Queue driver → **database** (`config/queue.php`, migration for the jobs table already exists);
    document the Hostinger cron for `schedule:run` and a `queue:work` worker (cron-restarted).
  - Branded `resources/views/errors/{403,404,419,500,503}.blade.php` (design-system styled).
  - Confirm `APP_DEBUG=false`, secrets only in `.env`; verify Breeze login throttling is active;
    enforce HTTPS/HSTS + security headers; review the CSRF-exempt Razorpay path.
  - `.env.example` + README deploy runbook (migrate → optimize → storage:link → cron → worker).
- **Build sequence:** (a) queue config + jobs table → (b) error pages → (c) security/env hardening +
  `.env.example` → (d) README runbook.
- **Tests** error pages render (200/correct status + branded); `/up` healthy; scheduler dry-run lists
  both `subscriptions:reconcile` and `model:purge-trashed`. (Queue/backup verified manually.)

---

## Bunch 2 · Comms & compliance *(parallel with Bunch 1)*

### ST-Email — Transactional email + verification + lifecycle mailables (S5) 🔴
- **Status:** ⬜ Planned. **Depends on:** ST-Infra (queued mail).
- **Scope**
  - Production mail = **Hostinger SMTP** via `.env` (keep `log` local/tests); `MAIL_*` in `.env.example`
    + README; note SPF/DKIM/DMARC for deliverability.
  - Enable `verified` on the tenant group **after** SMTP is confirmed (env-guarded so local/tests, which
    lack mail, still pass).
  - **Queue all mail.** Lifecycle mailables: welcome, verify-email, password-reset (deliverable),
    trial-ending (T-3 / T-0), payment-failed/dunning, staff invite (ST-Staff), invoice/receipt
    (ST-Billing). Triggered by `subscriptions:reconcile` (ST-Enforce) and the webhook (ST-Billing).
- **Build sequence:** (a) SMTP config + `.env.example` → (b) queue the mailables + build the templates →
  (c) wire triggers to reconcile/webhook → (d) turn on `verified` (staging-gated).
- **Tests** mailables render; correct job dispatched on each event (trial-ending on reconcile, invite on
  staff-add, receipt on charge); password-reset + verification flows green with `verified` on.

### ST-Legal — Legal & compliance pages (S6) 🔴
- **Status:** ⬜ Planned. **Depends on:** — (independent; **blocks Razorpay go-live**).
- **Scope**
  - Public `/legal/{terms,privacy,refund,contact}` routes + design-system Blade pages; footer links on
    the landing page ([welcome.blade.php](resources/views/welcome.blade.php)), register, and billing/
    checkout screens.
  - Privacy content must describe tenant customer **PII + prescriptions** handling, the 30-day
    soft-delete purge, tenant-as-controller / OSMS-as-processor / Razorpay-as-sub-processor (DPDP Act).
  - Real copy + business/GST identity supplied by owner (**D-S6a/c**) — structure ships now with a
    marked placeholder.
- **Build sequence:** (a) routes + views scaffold + footer links → (b) drop in owner copy before go-live.
- **Tests** each legal route returns 200 and is linked from the landing footer (smoke).

---

## Bunch 3 · Money path

### ST-Billing — Single-plan subscribe / cancel / invoices + Razorpay go-live (S4) 🔴
- **Status:** ⬜ Planned. **Depends on:** ST-Enforce, ST-Email, ST-Legal.
- **Simplified by single-plan:** no upgrade/downgrade/proration — just **subscribe (Basic), cancel, and
  invoices**. Builds on today's [BillingController](app/Http/Controllers/Tenant/BillingController.php)
  (which only has `subscribe`).
- **Scope**
  - Confirm/streamline the **Basic-only** subscribe → checkout → active path; drop the multi-tier
    validation (`in:basic,pro,enterprise` → single plan) but keep `config/billing.php` shape for future.
  - `BillingController::cancel` → `BillingService::cancelSubscription()` (Razorpay `cancel_at_cycle_end`);
    access continues to `current_period_end` (ties into ST-Enforce's `accessState`).
  - **Webhook hardening** ([RazorpayWebhookController](app/Http/Controllers/RazorpayWebhookController.php)):
    **idempotency** (dedupe by Razorpay event id) + handle `subscription.charged` → invoice row.
  - **Invoices:** `subscription_invoices` table (tenant-owned, `HasUuid` + `BelongsToTenant`) populated
    from charge webhooks; **GST-compliant PDF** via the existing dompdf; list + download on billing page.
  - **Go-live:** wire live keys/plan-id behind `isConfigured()`; the receipt/invoice emails come from
    ST-Email.
- **Build sequence:** (a) single-plan subscribe cleanup → (b) cancel flow + access-till-period-end →
  (c) webhook idempotency + charge→invoice → (d) invoice PDF + billing UI → (e) live-key go-live checklist.
- **Tests** `PhaseNNBillingTest` — subscribe (test mode) → active via webhook; cancel keeps access to
  period end; duplicate webhook = one state change (idempotent); charge event creates exactly one
  invoice; invoice PDF renders GST fields; tenant isolation on invoices.

---

## Bunch 4 · People & data

### ST-Staff — Staff management + global staff cap (S3 + S2-lite) 🟠
- **Status:** ⬜ Planned. **Depends on:** ST-Email (invite mail).
- **Today:** only the first registered user (`store_admin`) can ever exist — no invite/add path
  ([RegisteredUserController](app/Http/Controllers/Auth/RegisteredUserController.php)).
- **Scope**
  - `staff_invitations` table (tenant-owned, `HasUuid` + `BelongsToTenant`): email, role, signed token,
    `expires_at`, `accepted_at`, soft-deletes.
  - `Tenant\StaffController` (index / invite / resend / revoke / updateRole / remove) in the Settings
    hub; public `InvitationController` (show / accept — outside the tenant group, invitee has no tenant
    session yet) → creates the user with the invite's `tenant_id` + role.
  - **Global cap** `config('saas.max_staff')` (default 5) enforced before an invite is sent; over-cap →
    friendly "seat limit reached" upsell/notice (no tiers — one number for everyone).
  - Guards: never leave a tenant with zero `store_admin`; re-invite/expired-invite handling.
- **Build sequence:** (a) invitations table + model → (b) invite/manage UI + cap check →
  (c) public accept flow → (d) invite/removed mailables (via ST-Email).
- **Tests** `PhaseNNStaffTest` — invite creates a scoped invitation; accept creates a correctly-scoped
  user; cap blocks the (N+1)th invite; revoke/resend; last-admin guard; expired invite rejected; can't
  accept into the wrong tenant (isolation).

### ST-Lifecycle — Account & tenant data lifecycle (S10) 🟠
- **Status:** ⬜ Planned. **Depends on:** ST-Billing (cancel-on-close).
- **Today:** [ProfileController::destroy](app/Http/Controllers/ProfileController.php#L46) deletes the
  user only — the sole admin deleting their account **orphans the tenant + all data while billing keeps
  running**.
- **Scope**
  - Guard `ProfileController::destroy` — block deleting the **last `store_admin`** (mirrors ST-Staff's
    rule); a non-last member self-deleting just removes that user.
  - **"Close store"** flow (owner, in Settings): cancel the Razorpay subscription (ST-Billing) →
    optional data export → soft-delete/anonymize tenant + children → 30-day scheduled purge.
  - **Data export** ("download all my data"): bundle the tenant's customers/orders/inventory via the
    existing maatwebsite/excel exports (DPDP portability).
- **Build sequence:** (a) last-admin guard + test → (b) close-store flow (cancel → export → soft-delete →
  purge) → (c) data-export bundle.
- **Tests** `PhaseNNLifecycleTest` — last-admin cannot self-delete, non-last can; close-store cancels
  the subscription and schedules purge; export is tenant-scoped; isolation.

---

## Bunch 5 · Polish & harden

### ST-Onboard — Trial messaging + empty states (S7-lite) 🟡
- **Status:** ⬜ Planned. **Depends on:** ST-Enforce.
- **Simplified by single-plan:** **no plan picker** — onboarding stays fast (store name/logo →
  14-day trial). Adds trial-terms clarity + a first-run that teaches value.
- **Scope** trial-terms note on onboarding; liquid **empty states** with primary CTAs on inventory /
  orders / customers / dashboard; optional dismissible "first sale" checklist; optional purgeable demo
  data toggle (tenant-scoped).
- **Tests** empty states render for a fresh tenant; demo-data seeder is tenant-scoped + purgeable.

### ST-Harden — Backups + monitoring + restore drill (S8b) 🟡
- **Status:** ⬜ Planned. **Depends on:** ST-Infra.
- **Scope** `spatie/laravel-backup` (DB + `storage/app/public`) daily to off-box storage + documented
  restore; **Sentry** (free tier) for exceptions; wire `/up` to an uptime pinger; **do a real
  backup→restore drill** before go-live (untested backups = false confidence).
- **Tests** mostly operational/manual; document the drill outcome in this tracker.

---

## Completion plan (sequencing)

**Milestone 1 — Foundation up (Bunch 1 + Bunch 2 in parallel).** Ship ST-Enforce, ST-Infra, ST-Email,
ST-Legal. Outcome: access depends on a live subscription, the scheduler/queue run, mail sends, and the
legal pages exist. *This is the hard part and unblocks everything.*

**Milestone 2 — Money on (Bunch 3).** Ship ST-Billing and flip Razorpay live (needs the live merchant
account approved against the ST-Legal pages). Outcome: a store can **pay us online, automatically**.
→ **This is the minimum to take the first paying client.**

**Milestone 3 — Multi-seat + safe offboarding (Bunch 4).** Ship ST-Staff (with the cost-guard cap) and
ST-Lifecycle. Outcome: stores add staff within the cap; closing an account cancels billing cleanly.

**Milestone 4 — Polish + resilience (Bunch 5).** Ship ST-Onboard and ST-Harden. Outcome: a smooth
first-run and backed-up, monitored production ready to scale past the first 5 stores.

**Definition of launch-ready (the "5 clients, no errors, no contact" bar):** Milestones 1–3 complete,
`verified` mail live, backups running (from ST-Harden, pulled forward if needed), legal copy in place,
and the full `php artisan test` suite green with tenant-isolation coverage on every new SaaS action.

---

*Update this tracker as each item moves ⬜ Planned → 🟨 In progress → ✅ Done, mirroring the
FEATURE_TRACKER / BUG_TRACKER convention (status line + commit ref + test summary). Test suites continue
the `PhaseNN…Test` numbering from the domain features (currently through Phase21).*
