# OSMS — SaaS Model / Launch-Readiness Analysis

**Status:** 🟡 Living document — full-system audit of what stands between the current build and a
paid, public market launch.
**Releted Docs:** [SAAS_TRACKER.md](SAAS_TRACKER.md) · [SAAS_Payment_Prompt.md](SAAS_Payment_Prompt.md)
**Companion:** [QA_TESTING_REPORT_3.md](QA_TESTING_REPORT_3.md) · [BUG_TRACKER.md](BUG_TRACKER.md) ·
[FEATURE_TRACKER.md](FEATURE_TRACKER.md)
**Scope of this session:** the **SaaS business layer**, not the optical domain. The product features
(patients/customers, inventory, POS, kanban orders, analytics) are built and tested. This document
audits everything that makes OSMS a *sellable multi-tenant subscription product*: subscription
enforcement, plan limits, team management, billing self-service, transactional email, legal/compliance,
onboarding, and production operations.

> ⚠️ **Headline finding:** OSMS is a working optical app but **not yet a working SaaS**. The most
> serious gap is that **nothing enforces the subscription** — a store can register, start a 14-day
> trial, and use the app **forever for free**. Trial expiry, `past_due`, and `canceled` states are all
> recorded but never checked anywhere in the request lifecycle. Until Task S1 ships, there is no
> business.

> **Two items are explicitly out of scope of this report** (owner-directed):
> - **Superadmin panel** — the current [`Superadmin\DashboardController`](app/Http/Controllers/Superadmin/DashboardController.php)
>   is a known temporary stub. Catalogued in **Task S9** for completeness only.
> - **Payment gateway (Razorpay) go-live** — keys/plan-ids are not yet configured; the integration
>   will be finished when this SaaS update is worked on. This report covers the **app-side surfaces**
>   that must exist *around* the gateway (management, webhooks, invoices) but does not treat "turn on
>   Razorpay" as a task.

---

## Legend

- **Current** — how it works today, with file references.
- **Proposed** — what launch requires.
- **Approach** — recommended design + alternatives considered.
- **Affected features** — the butterfly list: everything that must change or be re-verified.
- **Edge cases / risks** — what can break.
- **Tests** — new/updated coverage required.
- **Decisions** — questions that block implementation.
- **Priority** — 🔴 launch-blocker · 🟠 launch-important · 🟡 fast-follow.

---

## Executive summary — the gap map

| # | Task | Priority | One-line gap |
|---|------|:--:|---|
| **S1** | Subscription lifecycle & access enforcement | 🔴 | Nothing checks subscription state; the app is free forever. |
| **S2** | Plan limits & feature gating | 🔴 | Tiers advertise limits/features that are never enforced. |
| **S3** | Staff / team management | 🟠 | Plans sell "N staff users" but there is no way to add staff. |
| **S4** | Subscription self-service (cancel / change plan / invoices) | 🟠 | Only "subscribe" exists — no cancel, upgrade, downgrade, or receipts. |
| **S5** | Transactional email & verification | 🔴 | Mail driver is `log`; email verification is disabled in prod. |
| **S6** | Legal & compliance pages | 🔴 | No Terms / Privacy / Refund policy — Razorpay & law require them. |
| **S7** | Onboarding & plan selection | 🟡 | No plan choice at signup; thin first-run experience. |
| **S8** | Production operations & hardening | 🔴 | No backups, error monitoring, branded error pages, or queue worker. |
| **S9** | Superadmin platform console (temp → real) | 🟠 | *(out of scope — catalogued only)* read-only stub. |
| **S10** | Account & tenant data lifecycle | 🟠 | Deleting the owner orphans the tenant and all its data. |

🔴 = must ship before charging a rupee · 🟠 = must ship before scaling past pilot stores · 🟡 = fast-follow.

---

# Task S1 — Subscription lifecycle & access enforcement 🔴

## Current
- [`Subscription`](app/Models/Subscription.php) has `status` (`active|past_due|canceled|trialing`),
  `tier`, and `current_period_end`, plus `isActive()` and `isPastDue()` helpers.
- [`OnboardingController::store()`](app/Http/Controllers/OnboardingController.php#L76) creates a
  `trialing` subscription with `current_period_end = now()->addDays(14)`.
- The [Razorpay webhook](app/Http/Controllers/RazorpayWebhookController.php) correctly flips
  `status` on `subscription.activated/charged/cancelled/…` events.
- **The gap:** `isActive()` / `isPastDue()` / `current_period_end` are **never called** in any
  middleware, controller, or provider (verified by grep across `app/Http/Middleware` and
  `app/Providers`). Tenant routes are guarded only by `['auth','onboarded']`
  ([routes/web.php](routes/web.php#L40)). **A trialing subscription whose `current_period_end` has
  passed still has full access.** So does a `past_due` or `canceled` one. Trial expiry is cosmetic.

## Proposed
Access to the tenant workspace must depend on a **live, valid** subscription. Expired trials and
lapsed/cancelled subscriptions must lose access (with a clear "renew to continue" path), while a
short grace window absorbs failed-charge retries.

## Approach — recommended: an `EnsureSubscriptionActive` middleware + a derived state machine
- **Single source of truth on the model.** Add `Subscription::accessState(): 'active'|'grace'|'locked'`:
  - `active` — `status ∈ {active, trialing}` **and** `current_period_end` is in the future (or null
    for a freshly-created active sub the webhook hasn't stamped yet).
  - `grace` — `status = past_due` within a **7-day dunning window** (config
    `billing.grace_days`), computed from `current_period_end`.
  - `locked` — `trialing`/`active` past `current_period_end`, or `past_due` beyond grace, or
    `canceled`.
- **Middleware `subscribed`** appended to the tenant route group **after** `onboarded`. On `locked`
  it redirects to `tenant.billing.index` with a blocking banner ("Your trial has ended / payment is
  past due — choose a plan to continue"). On `grace` it lets the request through but flashes a
  non-blocking warning.
- **Always-reachable routes.** `billing.*`, `profile.*`, `logout` must bypass the gate (you can't ask
  someone to pay if the pay page is itself locked). Implement as an allowlist inside the middleware or
  by mounting billing outside the `subscribed` group.
- **Read-only vs hard-lock (decision D-S1a).** Recommended for v1: **hard-lock** the workspace
  (redirect all module routes to billing) — simplest and unambiguous. A softer "read-only, can view
  but not create" mode is more work (every store/update path must branch) and can wait.
- **Scheduled reconciliation.** A daily `subscriptions:reconcile` command
  ([routes/console.php](routes/console.php)) flips `trialing` rows past `current_period_end` to a
  terminal state and (Task S5) fires trial-ending / payment-failed emails. This keeps state correct
  even if a webhook is missed.

**Alternatives considered:** (B) check in a base controller — rejected, easy to forget on new
controllers, no coverage of direct route additions. (C) global `booted` query scope — rejected, mixes
authz into the ORM and complicates the superadmin bypass.

## Affected features (butterfly)
**Middleware & routing**
- New `App\Http\Middleware\EnsureSubscriptionActive`; alias `subscribed` in
  [bootstrap/app.php](bootstrap/app.php); apply to the tenant group in [routes/web.php](routes/web.php),
  with `billing.*`/`profile.*` exempt.
**Models**
- `Subscription`: `accessState()`, `isInGracePeriod()`, `trialDaysLeft()`; cast `current_period_end`
  already present.
- `Tenant`: convenience `hasActiveAccess()` delegating to the subscription (handles the "no
  subscription row" edge → treat as locked).
**Views**
- `layouts/app.blade.php`: trial-countdown / grace-period banner (uses design tokens, not hardcoded).
- `tenant/billing/index`: "locked" state messaging distinct from the normal upsell.
**Console**
- New `subscriptions:reconcile` scheduled daily (pairs with the existing `model:purge-trashed`).

## Edge cases / risks
- **Tenant with no `Subscription` row at all** (data drift, failed onboarding txn) → must be treated as
  `locked`, never as "unlimited access."
- **Clock/timezone** on `current_period_end` (stored as `date`, not `datetime`) — decide whether
  expiry is end-of-day in app timezone; document it.
- **Webhook lag** right after payment: a just-activated sub may briefly have a stale
  `current_period_end`. Grace + the `success` redirect message
  ([BillingController::success](app/Http/Controllers/Tenant/BillingController.php#L71)) cover this.
- **Superadmin** must bypass the gate entirely (they don't have a tenant subscription).
- **Lock-out loops** — the exemption allowlist must be correct or a locked user can't even reach
  billing to recover.

## Tests
- Locked when trial expired; locked when `canceled`; locked when `past_due` beyond grace; **allowed**
  during grace (with warning); allowed when `active`/`trialing` in-window. Billing page reachable while
  locked. Superadmin bypasses. `subscriptions:reconcile` flips an expired trial and leaves a live one.
  Tenant-isolation on all of the above.

## Decisions (blocking): **D-S1a** (hard-lock vs read-only), **D-S1b** (grace-window length),
**D-S1c** (expiry = end-of-day in which timezone).

---

# Task S2 — Plan limits & feature gating 🔴

## Current
- [config/billing.php](config/billing.php) advertises concrete limits — Basic "Up to 2 staff users",
  Pro "Up to 8", Enterprise "Unlimited"; Pro-and-up get "Analytics & profit reports" and "Excel
  export"; Enterprise gets "Multi-branch", "SMS/WhatsApp add-ons".
- **None of this is enforced.** Analytics and export are gated by **role**
  (`role:store_admin,superadmin` in [routes/tenant.php](routes/tenant.php#L72-L82)), not by **tier** —
  a Basic-tier store admin gets full analytics. Staff limits are moot because there is no staff-add
  path at all (Task S3). Feature copy is marketing text with no runtime meaning.

## Proposed
Each tier must grant exactly what it advertises: a hard cap on staff seats, and Pro/Enterprise-only
features actually withheld from Basic — with a graceful "upgrade to unlock" prompt rather than a 403.

## Approach — recommended: config-driven capabilities + a `TierGate`
- **Extend `config/billing.php`** so each plan carries machine-readable limits, not just display
  strings: `'limits' => ['staff' => 2]`, `'features' => ['analytics' => false, 'export' => false,
  'multi_branch' => false]` (Pro flips analytics/export true; Enterprise flips all).
- **`Subscription::allows(string $feature): bool`** and **`limit(string $key): int`** reading the
  current tier's config. A single helper `tenant_can('analytics')` (or a Gate/policy) used by both
  routes and Blade (`@can`).
- **Route gating:** replace/augment the role middleware on analytics & export with a `feature:analytics`
  / `feature:export` middleware (still ANDed with role). Basic tier → redirect to billing with
  "Analytics is a Pro feature."
- **Limit enforcement:** staff-seat cap checked in the Task S3 invite path
  (`Subscription::limit('staff')` vs current user count). Over-limit → upsell.
- **UI:** locked features shown as disabled with an "Upgrade" affordance (never silently missing —
  discoverability drives upgrades), honouring the liquid-motion design system.

**Alternatives considered:** hardcoding tier checks inline (`if tier === 'pro'`) — rejected, scatters
business rules and breaks the moment tiers are renamed. Config + one gate keeps it declarative.

## Affected features (butterfly)
- `config/billing.php`: add `limits` + `features` to every plan.
- `Subscription` model: `allows()`, `limit()`.
- New `feature` middleware (or a `TierGate`/Gate registration in a provider).
- [routes/tenant.php](routes/tenant.php): analytics/export/(future add-ons) gated by feature, not only
  role.
- Sidebar / nav + billing page: show locked features with upgrade CTA.
- Staff invite path (Task S3): seat-limit check.

## Edge cases / risks
- **Downgrade with over-limit data** — a store on Pro with 6 staff downgrades to Basic (2). Decide:
  block the downgrade, or allow it and freeze extra seats read-only (**D-S2a**).
- **Trial tier** — a `trialing` sub has `tier=basic` by default; decide whether trials get **Pro
  features** to drive conversion (**D-S2b**, recommended: trial = full Pro so they experience the
  value).
- Enterprise "unlimited" → represent as a sentinel (e.g. `PHP_INT_MAX` / `null`), never a magic
  number in comparisons.

## Tests
- Basic tier blocked from analytics/export; Pro allowed; trial gets the decided feature set; staff
  cap blocks the (N+1)th invite; downgrade behaviour per D-S2a; tenant isolation.

## Decisions (blocking): **D-S2a** (downgrade over-limit), **D-S2b** (trial feature level),
**D-S2c** (final limit numbers per tier — confirm the config values).

---

# Task S3 — Staff / team management 🟠

## Current
- The role system exists — [`EnsureUserRole`](app/Http/Middleware/EnsureUserRole.php) supports
  `store_admin` / `store_staff` / `superadmin`, and analytics/billing/settings are admin-gated.
- But **only one user per store can ever exist**: [`RegisteredUserController`](app/Http/Controllers/Auth/RegisteredUserController.php#L44)
  creates the first user as `store_admin`, and there is **no route, controller, or view to invite or
  create additional staff**. `store_staff` is a role with no way to mint one.
- Plans sell "Up to 2 / 8 / unlimited staff users" — currently unachievable.

## Proposed
Store admins can invite staff by email, assign a role (`store_admin` / `store_staff`), see pending &
active members, and revoke access — all scoped to their tenant and capped by their plan (Task S2).

## Approach — recommended: email-invite flow with signed links
- **`staff_invitations` table** (`tenant_id`, `email`, `role`, `token`, `expires_at`, `accepted_at`,
  soft-deletes) using `HasUuid` + `BelongsToTenant` per the multi-tenancy rule (**mandatory** for any
  new tenant-owned table).
- **Invite:** admin submits email + role → row created, signed invite email sent (Task S5). Seat-limit
  checked against `Subscription::limit('staff')` before sending.
- **Accept:** invitee follows the signed link → sets name/password → user created with the invite's
  `tenant_id` + `role`, invitation marked accepted. Auto-`onboarded` (tenant already exists), skips the
  store-creation onboarding.
- **Manage:** a "Team" section in the Settings hub — list members, resend/revoke invite, change role,
  remove a member (with the C1-style guard: can't remove the last `store_admin`).
- **Registration guard:** public `register` stays "new store" only; invited users never hit it.

## Affected features (butterfly)
**Database & models**
- New `staff_invitations` (tenant-owned). `User` gains an `invitedBy`/status concept only if needed.
**Controllers & routes**
- New `Tenant\StaffController` (index/invite/resend/revoke/updateRole/remove) + a public
  `InvitationController` (show/accept) mounted outside the tenant group (invitee has no tenant session
  yet).
- `EnsureTenantOnboarded` interplay: accepted invitees already have `tenant_id`, so they pass.
**Views**
- Team management inside the Settings hub (`profile.edit` currently absorbs settings — see
  [SettingsController](app/Http/Controllers/Tenant/SettingsController.php)); invite-accept page
  (glass/auth styling).
**Email (Task S5)**
- Invitation mailable + accepted/removed notifications.

## Edge cases / risks
- **Seat limits** (Task S2): count pending invites toward the cap or only active users? (**D-S3a**).
- **Re-inviting an existing email**, invite to an email already in another tenant (a user belongs to
  exactly one tenant today — cross-tenant membership is out of scope).
- **Last-admin protection** — never leave a tenant with zero `store_admin`.
- **Expired/expired-then-clicked invites**; token replay; tenant isolation on the invitation table.

## Tests
- Invite creates a scoped invitation; accept creates a correctly-scoped user; seat cap blocks over
  limit; revoke/resend; last-admin guard; expired-invite rejected; **tenant isolation** on invitations
  and on the accept flow (can't accept into the wrong tenant).

## Decisions (blocking): **D-S3a** (do pending invites consume seats), **D-S3b** (invite link TTL).

---

# Task S4 — Subscription self-service: cancel / change plan / invoices 🟠

## Current
- [`BillingController`](app/Http/Controllers/Tenant/BillingController.php) supports exactly one action:
  `subscribe` (create) → checkout → `success` message. There is **no cancel, upgrade, downgrade,
  resume, or payment-history/invoice** surface.
- The webhook records terminal states but the tenant has no way to *initiate* a cancellation, and
  `subscribe` explicitly **blocks** creating a new sub while one is active
  ([BillingController::subscribe](app/Http/Controllers/Tenant/BillingController.php#L38-L41)) — so a
  plan change is currently impossible from the UI.

## Proposed
A store admin can, from the billing page: cancel (at period end), change tier (upgrade/downgrade),
resume a cancelled-but-in-period sub, and view/download past invoices with GST details.

## Approach
> The **Razorpay API wiring** for these actions is part of the deferred gateway work; this task is the
> **app-side design + surfaces** so the gateway work drops in cleanly.

- **Cancel:** `BillingController::cancel` → `BillingService::cancelSubscription()` (Razorpay
  `cancel_at_cycle_end=1`), set local `status` accordingly; access continues until
  `current_period_end` (ties into S1's `accessState`).
- **Change plan:** `BillingService::updateSubscription()` (Razorpay plan swap) rather than
  create-new; remove the "one active sub" hard block and replace with an update path. Define proration
  policy (**D-S4a**).
- **Invoices:** persist charge events from the webhook (`subscription.charged`) into a
  `subscription_invoices` table (or fetch from Razorpay on demand) and render a **GST-compliant PDF**
  (reuse dompdf, already a dependency) — needed for Indian B2B customers to claim input credit.
- **Webhook hardening:** add **idempotency** (dedupe by Razorpay event id) and handle
  `subscription.charged` → invoice row; the current handler
  ([RazorpayWebhookController](app/Http/Controllers/RazorpayWebhookController.php)) is not idempotent
  and ignores charge/invoice events.

## Affected features (butterfly)
- `BillingController`: `cancel`, `changePlan`, `resume`, `invoices`, `invoicePdf` + routes.
- `BillingService`: `cancelSubscription`, `updateSubscription` (deferred impl, stubs now).
- New `subscription_invoices` table (tenant-owned) + model, if persisting invoices.
- `RazorpayWebhookController`: idempotency + charge/invoice handling.
- `tenant/billing/index`: manage-plan UI (cancel/change/resume, invoice list).

## Edge cases / risks
- **Proration / mid-cycle change** semantics (**D-S4a**).
- **Cancel then re-subscribe** within the same period.
- **Webhook out-of-order / duplicate delivery** — idempotency is essential.
- **Downgrade** interacts with S2's over-limit rule.

## Tests
- Cancel sets pending-cancel and preserves access to period end; change-plan updates tier; resume;
  invoice PDF renders with GST fields; webhook idempotency (duplicate event = one state change);
  charge event creates one invoice. Tenant isolation on invoices.

## Decisions (blocking): **D-S4a** (proration), **D-S4b** (persist invoices locally vs fetch on
demand), **D-S4c** (GST number/HSN details required on invoice — confirm fields).

---

# Task S5 — Transactional email & verification 🔴

## Current
- `config/mail.php` default mailer is **`log`** — in production, no email is actually sent.
- Email verification is **deliberately disabled** for the workspace: [routes/web.php](routes/web.php#L38)
  notes *"For production (Hostinger), add 'verified' middleware here"* — the tenant group runs
  `['auth','onboarded']` **without** `verified`. Breeze's verification scaffolding exists but isn't
  enforced.
- Password reset relies on mail that currently goes to the log.
- No billing/trial/staff lifecycle emails exist.

## Proposed
Real transactional email in production: verified sign-up, deliverable password reset, and the
lifecycle emails the other tasks depend on (trial ending, payment failed, invite, invoice/receipt).

## Approach
- **Production mail driver:** configure SMTP (Hostinger mailbox or a transactional provider —
  Postmark/Resend/SES) via `.env`; keep `log` for local/tests. Add a `MAIL_*` block to
  `.env.example` and document in the README.
- **Enforce verification** by adding `verified` to the tenant group **once mail is live** (guard with
  env so local/test — which lack mail — still work; tests already avoid it).
- **Queue the mail:** emails must be queued (see S8 queue worker) so checkout/invite requests don't
  block on SMTP.
- **Lifecycle mailables:** trial-ending (T-3, T-0), payment-failed/dunning, staff invite (S3),
  invoice/receipt (S4), welcome. Triggered by `subscriptions:reconcile` (S1) and webhook (S4).

## Affected features (butterfly)
- `config/mail.php` / `.env.example` / README.
- Tenant route group: conditional `verified`.
- Queue infra (S8) — mail should be queued.
- Mailables + notifications for S1/S3/S4 lifecycle events.

## Edge cases / risks
- **Verification lockout:** enabling `verified` before mail is reliably delivering locks everyone out
  — sequence this **after** SMTP is verified in staging.
- **Deliverability:** SPF/DKIM/DMARC for the sending domain or trial/receipt mail lands in spam.
- **Local/test parity:** never require live mail in the test suite.

## Tests
- Password-reset & verification flows (Breeze tests exist — re-verify with `verified` on); mailables
  render; queued jobs dispatched on the right events; trial-ending email fired by reconcile.

## Decisions (blocking): **D-S5a** (SMTP vs transactional provider), **D-S5b** (turn on `verified` at
launch — yes/no and when).

---

# Task S6 — Legal & compliance pages 🔴

## Current
- The landing page ([welcome.blade.php](resources/views/welcome.blade.php), 144 lines) has **no links
  to Terms of Service, Privacy Policy, Refund/Cancellation Policy, or Contact** (grep found none).
  There are no routes or views for any legal page.

## Proposed
Public, linkable **Terms of Service, Privacy Policy, Refund & Cancellation Policy, and Contact Us**
pages, linked from the landing page footer and the signup/checkout flows.

## Approach
- **Why this is a hard blocker, not polish:** **Razorpay merchant onboarding in India requires**
  publicly accessible Terms, Privacy, Refund/Cancellation, and Contact pages before activating live
  payments. Separately, India's **DPDP Act 2023** (and general SaaS duty of care) requires a privacy
  policy describing what personal data (patient/customer PII, prescriptions) is collected and how it's
  processed. Selling a B2B product that stores patients' health-adjacent data without these is a legal
  and commercial non-starter.
- Add static, tokenized Blade pages under `/legal/*` (`terms`, `privacy`, `refund`, `contact`),
  linked in the landing footer, the register page, and the billing/checkout screens.
- Content should be reviewed by the owner / a lawyer; the engineering task is the routes, views,
  linking, and a contact mechanism (email or form).
- **Data-processing specifics** for the privacy policy: OSMS stores customer PII and prescriptions
  per tenant; document retention (note the 30-day soft-delete purge from FG-Delete), the tenant as
  data controller / OSMS as processor, and Razorpay as a sub-processor.

## Affected features (butterfly)
- New `routes/web.php` public routes + `legal/*` views (design-system styled).
- Landing footer, register, billing/checkout: footer links.
- README/ops: note the lawyer-review requirement.

## Edge cases / risks
- **Placeholder legal text shipping to production** — must be real, owner-approved content, not lorem.
- Contact form (if chosen over mailto) needs spam protection + the mail infra from S5.

## Tests
- Each legal route returns 200 and is linked from the landing footer (smoke test).

## Decisions (blocking): **D-S6a** (who provides the legal copy), **D-S6b** (contact = email link vs
form), **D-S6c** (business entity / GST identity to name in the documents).

---

# Task S7 — Onboarding & plan selection 🟡

## Current
- [`OnboardingController::store()`](app/Http/Controllers/OnboardingController.php) collects only
  store_name / tax_id / address / logo, then **auto-starts a 14-day `basic` trial**. The user never
  chooses a plan, never sees pricing, and lands on an empty dashboard.
- No sample/demo data, no guided first-run, no empty-state coaching for a brand-new store.

## Proposed
A signup-to-value flow: pick (or defer) a plan, understand the trial, and land on a dashboard that
teaches the first actions (add inventory → make a sale).

## Approach
- **Plan awareness at onboarding:** show the trial terms and let the store optionally pick the tier
  they're trialing (drives the S2-B decision on trial feature level). Defaulting to "trial Pro" +
  choose-later at conversion is the lowest-friction path.
- **First-run guidance:** empty states on inventory/orders/customers with a primary CTA (the design
  system's liquid empty-states), and optionally a dismissible checklist ("Add your first product",
  "Record your first sale").
- **Optional demo data** toggle at onboarding for evaluation (clearly labelled, easily purged).

## Affected features (butterfly)
- `onboarding/create` view + `OnboardingController::store` (optional tier field).
- Empty-state partials on the main list/dashboard views.
- Optional demo-data seeder scoped to the new tenant.

## Edge cases / risks
- Demo data must be tenant-scoped and trivially removable (don't pollute real analytics).
- Keep onboarding fast — don't trade the "inline, low-friction" ethos for a long wizard.

## Tests
- Onboarding with/without tier selection; empty states render for a fresh tenant; demo-data seeder is
  tenant-scoped and purgeable.

## Decisions: **D-S7a** (plan selection at signup vs at conversion), **D-S7b** (ship demo data?).

---

# Task S8 — Production operations & hardening 🔴

## Current
- Dev runs on Herd; production targets **Hostinger + MySQL** (per CLAUDE.md). Several
  production-only concerns are unaddressed in the repo:
  - **No queue worker** — `config/queue.php` default is likely `sync`; S5 email should be async.
  - **No error monitoring** (no Sentry/Flare) and **no branded 404/500** pages (default Laravel).
  - **No documented backup strategy** for the MySQL DB or the `public/logos` uploads.
  - **Logo storage:** onboarding writes to the `public` disk via `Storage::url()`
    ([OnboardingController](app/Http/Controllers/OnboardingController.php#L50)) — requires
    `php artisan storage:link` and correct permissions on Hostinger, and a plan for durability.
  - **Security headers / HTTPS enforcement / auth throttling** not explicitly configured (Breeze
    ships login throttling; verify it's active).
  - **Health check** `/up` exists ([bootstrap/app.php](bootstrap/app.php)) — good; wire it to uptime
    monitoring.

## Proposed
A production environment that is backed up, observable, resilient, and secure enough to hold other
businesses' customer data.

## Approach
- **Queue:** database (or Redis) queue + a worker (Hostinger cron running `queue:work` or a
  supervisor); move mail/exports to the queue.
- **Backups:** `spatie/laravel-backup` (DB + `storage/app/public`) on a daily schedule to off-box
  storage; document restore.
- **Monitoring:** Sentry (or Flare) for exceptions; wire `/up` to an uptime pinger.
- **Branded error pages:** `resources/views/errors/{404,403,419,500,503}.blade.php` in the design
  system.
- **Security:** enforce HTTPS + HSTS, security headers, confirm login/`throttle` limits, verify
  `APP_DEBUG=false` and secrets only in `.env`; review the CSRF-exempt webhook path.
- **Storage durability:** confirm `storage:link` in deploy, and decide whether logos move to object
  storage later.
- **Deploy runbook:** migrate, `optimize`, `storage:link`, cron for `schedule:run` (the S1 reconcile
  and existing purge depend on it), queue worker.

## Affected features (butterfly)
- `config/queue.php`, cron entries, `.env.example`, README deploy section.
- New `errors/*` views.
- New backup + monitoring packages/config.
- Verify scheduler is actually running on Hostinger (both `model:purge-trashed` and the new
  `subscriptions:reconcile` silently do nothing without `schedule:run` in cron).

## Edge cases / risks
- **Scheduler not wired** → trials never expire (breaks S1) and trash never purges. Single highest
  operational risk.
- **`APP_DEBUG=true` in prod** → leaks stack traces / secrets.
- **Backups untested** → false confidence; must do a restore drill.

## Tests
- Error pages render; `/up` returns healthy; (manual) queue worker processes a job; (manual) backup +
  restore drill; scheduler dry-run lists both commands.

## Decisions: **D-S8a** (queue driver — DB vs Redis), **D-S8b** (backup destination), **D-S8c**
(monitoring vendor).

---

# Task S9 — Superadmin platform console (temp → real) 🟠 *(out of scope — catalogued only)*

## Current
- [`Superadmin\DashboardController`](app/Http/Controllers/Superadmin/DashboardController.php) is a
  single read-only page: tenant list with counts + platform totals. Acknowledged as a temporary stub.

## Proposed (for when it's picked up)
A real operator console: **suspend/reactivate a tenant**, **override/extend a subscription or trial**
(comp accounts, fix billing disputes), **impersonate** a tenant for support, **MRR / churn / active-
trial** metrics, and an **audit log** of operator actions. Ties directly to S1 (`accessState`) and S4
(subscription control).

*No further breakdown here — this task is deferred per the report's scope note. Listed so the
dependency from S1/S4 (operator override of subscription state) is visible.*

---

# Task S10 — Account & tenant data lifecycle 🟠

## Current
- [`ProfileController::destroy`](app/Http/Controllers/ProfileController.php#L46) deletes the **user
  only**. If the store's sole `store_admin` deletes their account, the **`Tenant`, its customers,
  inventory, orders, payments, and subscription are all orphaned** — no cascade, no cleanup, and (if
  the subscription is live) **billing continues** with no one able to log in.
- There is no tenant-level "close my store / export my data" flow, and no data-export for a tenant's
  own records beyond the per-module Excel exports.

## Proposed
Deleting the last admin must be prevented or must cleanly offboard the whole tenant (cancel
subscription, purge or export data). Tenants should be able to export their data and formally close
their store.

## Approach
- **Guard `ProfileController::destroy`:** block deleting the last `store_admin` of a tenant (mirror
  Task S3's last-admin rule); a non-last member deleting themselves just removes that user.
- **"Close store" flow (owner):** an explicit tenant-offboarding action that cancels the Razorpay
  subscription (S4), optionally exports data, and soft-deletes/anonymizes the tenant + children
  (respecting the 30-day purge already in place for FG-Delete).
- **Data export (DPDP / portability):** a "download all my data" action producing the tenant's
  customers/orders/inventory (reuse maatwebsite/excel exports, bundled).

## Affected features (butterfly)
- `ProfileController::destroy` (last-admin guard).
- New tenant offboarding controller/route + confirmation UI (Settings hub).
- Subscription cancel-on-close (depends on S4).
- Data-export bundle.

## Edge cases / risks
- **Live subscription on a deleted account** → keeps charging (real money / chargeback risk).
- **Cascade correctness** — cancelling should not instantly hard-delete data the owner may need to
  export first; sequence export → cancel → soft-delete → scheduled purge.
- **Tenant isolation** on export and close.

## Tests
- Last-admin cannot self-delete; non-last member can; close-store cancels subscription and schedules
  purge; data export is tenant-scoped; isolation.

## Decisions (blocking): **D-S10a** (last-admin: block vs cascade-close), **D-S10b** (retention window
after close before hard purge).

---

# Consolidated butterfly matrix

| Module / file | S1 Enforce | S2 Limits | S3 Staff | S4 Manage | S5 Email | S6 Legal | S7 Onboard | S8 Ops | S10 Lifecycle |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| `Subscription` model | ● | ● | ○ | ● | — | — | — | — | ○ |
| Middleware / bootstrap | ● | ● | ○ | — | ○ | — | — | ○ | — |
| `config/billing.php` | ○ | ● | ○ | ● | — | — | ○ | — | — |
| Tenant route group | ● | ● | ○ | — | ● | — | — | ○ | — |
| `BillingController`/`Service` | ○ | ○ | — | ● | ○ | ○ | ○ | — | ○ |
| Razorpay webhook | ○ | — | — | ● | ○ | — | — | — | ○ |
| New tables (invites/invoices) | — | — | ● | ● | — | — | — | — | ○ |
| `OnboardingController` | ○ | — | ○ | — | ○ | — | ● | — | — |
| `ProfileController` | — | — | ○ | — | — | — | — | — | ● |
| Auth (register/verify) | — | — | ● | — | ● | — | — | ○ | — |
| Views: billing/settings | ● | ● | ● | ● | — | ○ | ○ | — | ● |
| Views: landing/legal | — | — | — | ○ | — | ● | ○ | — | — |
| Views: errors/empty-states | — | ○ | — | — | — | — | ● | ● | — |
| Console / scheduler | ● | — | — | ○ | ● | — | — | ● | ○ |
| Mail / queue infra | — | — | ● | ● | ● | — | — | ● | ○ |
| Tests | ● | ● | ● | ● | ● | ○ | ○ | ○ | ● |

● = direct change · ○ = re-verify / minor · — = none

---

# Suggested sequencing (once decisions are locked)

**Phase A — make it a business (launch-blockers, do first):**
1. **S1 Subscription enforcement** — the keystone; nothing else about "SaaS" matters until access
   depends on payment. Ship the middleware + `accessState` + reconcile command.
2. **S8 Ops baseline (partial)** — stand up the **queue worker + scheduler cron** early, because S1's
   reconcile and S5's mail depend on them; branded error pages + `APP_DEBUG=false`.
3. **S5 Email** — required for verification, password reset, and every lifecycle email S1/S3/S4 emit.
4. **S2 Plan limits & gating** — makes tiers mean something; small once S1's `Subscription` helpers
   exist.
5. **S6 Legal pages** — independent, can be built in parallel; **blocks Razorpay go-live**.

**Phase B — make it sellable at scale (before pilot → GA):**
6. **S4 Subscription self-service** (app-side; gateway calls land with the deferred Razorpay work).
7. **S3 Staff/team management** — unlocks the multi-seat value proposition the plans advertise.
8. **S10 Data lifecycle** — close the account-deletion orphan hole before real customer data grows.

**Phase C — polish & scale:**
9. **S7 Onboarding/plan-selection & empty states** — conversion optimization.
10. **S8 remainder** — backups + monitoring hardening + restore drill.
11. **S9 Superadmin console** — *(deferred; when picked up)*.

> S1 → (S8 infra + S5) → S2 is the **critical path**. S6 is fully parallel and gates the payment
> go-live specifically. S3/S4/S10 build on S1's subscription helpers and S5's mail.

---

# Open decisions

## 🔴 Blocking (must answer before Phase A build)
- **D-S1a** — Locked workspace: **hard-lock** (redirect all to billing) or **read-only** (view but
  not create)? *Recommended: hard-lock for v1.*
- **D-S1b** — Grace-period length for `past_due` (dunning window). *Recommended: 7 days.*
- **D-S1c** — Trial/period expiry evaluated as **end-of-day in which timezone**? *Recommended: app
  timezone (IST).*
- **D-S2b** — Do **trials get full Pro features** (drive conversion) or only Basic? *Recommended:
  trial = Pro.*
- **D-S2c** — Confirm the **exact seat limits & feature flags** per tier (the config values).
- **D-S5a** — Production mail: **Hostinger SMTP** or a transactional provider (Postmark/Resend/SES)?
- **D-S5b** — Turn on `verified` email enforcement at launch? *Recommended: yes, after SMTP verified
  in staging.*
- **D-S6a / D-S6c** — Who supplies the **legal copy**, and what **business/GST entity** is named in
  Terms/Privacy/invoices?

## 🟠 Blocking for Phase B
- **D-S2a** — Downgrade with over-limit staff: block the downgrade, or allow + freeze extra seats?
- **D-S3a** — Do **pending invites** consume plan seats?
- **D-S3b** — Invite-link TTL. *Recommended: 7 days.*
- **D-S4a** — Plan-change **proration** policy (immediate + prorate vs at next cycle).
- **D-S4b** — Invoices: **persist locally** vs fetch from Razorpay on demand.
- **D-S4c** — Required **GST/HSN fields** on the subscription invoice PDF.
- **D-S10a** — Last-admin deletion: **block** or **cascade-close the tenant**?
- **D-S10b** — Retention window after store-close before hard purge. *Recommended: 30 days (matches
  FG-Delete).*

## 🟡 Non-blocking (confirm, else proceed as written)
- **D-S6b** — Contact = mailto link vs form. *Default: mailto for v1.*
- **D-S7a** — Plan selection at **signup** vs at **conversion**. *Default: choose-at-conversion,
  trial full Pro.*
- **D-S7b** — Ship **demo data** toggle? *Default: yes, dismissible/purgeable.*
- **D-S8a/b/c** — Queue driver (DB vs Redis), backup destination, monitoring vendor. *Defaults: DB
  queue, off-box daily backup, Sentry.*

---

*Next step: this document is the SaaS build spec, parallel to QA_TESTING_REPORT_3.md (the domain-feature
spec). **S1 is the critical path and can start as soon as D-S1a–c are answered** — it's the difference
between a demo and a business. S6 (legal) is fully independent and unblocks the eventual Razorpay
go-live. Each task gets a `PhaseNN…Test` suite with tenant-isolation coverage per the project testing
rules.*
