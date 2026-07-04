# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Project

OSMS — multi-tenant B2B SaaS for optical retail. Migrated from Next.js + Supabase to
Laravel 12 + Blade + Bootstrap 5 + MySQL (Hostinger). Domains: patients/prescriptions,
barcode POS, frame/lens inventory, kanban orders, analytics, Razorpay subscriptions.

## Tech stack

- Laravel 12 (PHP 8.2+), Blade + Bootstrap 5 (SCSS compiled via Vite), Alpine.js for the order builder
- MySQL in production, SQLite locally and in tests (`:memory:`)
- Breeze auth; barryvdh/laravel-dompdf, maatwebsite/excel, simplesoftwareio/simple-qrcode, razorpay/razorpay

## Commands

```bash
php artisan test            # full suite (PHPUnit)
php artisan migrate --seed  # rebuild + demo data
npm run build               # compile Bootstrap SCSS + JS
```

On Windows the dev server runs via **Laravel Herd** (PHP/Composer live in
`C:\Users\viraj\.config\herd\bin`, not on PATH for non-interactive shells — prepend it).
`php artisan serve` may fail to bind a port here; verify via `php artisan test` instead.

## Multi-tenancy (CRITICAL)

Every store-owned model uses `App\Models\Concerns\BelongsToTenant`, which:
1. applies `App\Models\Scopes\TenantScope` (constrains all queries to `auth()->user()->tenant_id`;
   superadmins bypass), and
2. auto-stamps `tenant_id` on create.

This is the app-layer replacement for Supabase RLS. **Any new tenant-owned table/model must
use this trait** and have a `tenant_id` UUID column. Never query tenant data without it.

## Conventions

- Business tables use UUID primary keys (`HasUuid` trait). `users` stays bigint (Breeze).
- Tenant routes live in `routes/tenant.php`, included under the `tenant` prefix +
  `tenant.` name + `['auth','onboarded']` middleware in `routes/web.php`.
- Controllers: `App\Http\Controllers\Tenant\*`. Role gating via `role:` middleware.
- Money formatted as `₹ ` + `number_format(...)`. Order `balance_due` is kept in sync by the
  `Order` model's saving hook (`total_amount - advance_paid`).
- Shared chrome uses `safe_route()` (helper) so links to not-yet-built routes don't throw.
- Design tokens (deep optical blue `#004f75`, glass, card-lift, print rules) live in
  `resources/sass/app.scss`. Don't hardcode brand colors inline.

## Testing

Add a `PhaseNXxxTest` per feature. Always include a tenant-isolation assertion for new
tenant-owned data. Run `php artisan test` before considering a change done.

## Local artifacts (`_artifacts/`)

All planning/tracking docs live in **`_artifacts/`**, which is **gitignored** (local-only, never
committed): `BUG_TRACKER.md`, `FEATURE_TRACKER.md`, `QA_TESTING_REPORT_*.md`, `SAAS_TRACKER.md`,
`SAAS_LAUNCH_REPORT.md`. Read/update these for context on features, bugs, and build order — but keep
**every** such generated report, tracker, or scratch doc inside `_artifacts/` so it stays out of git.
Only `CLAUDE.md` and `README.md` remain tracked at the repo root. Links inside these docs are relative
to `_artifacts/`, so source-file references use a `../` prefix (e.g. `../app/Models/Order.php`).

## [VISUAL DESIGN SYSTEM DIRECTIVE]

OSMS uses an **iOS-inspired premium design system**. The single source of truth is the
`:root` token block in `resources/sass/app.scss`. Tailwind is **dead scaffolding** (not wired
into Vite) — ignore it. All styling flows through Bootstrap 5 SCSS + the OSMS custom layer.

**Non-negotiable rule:** never hardcode a hex color, font-size, shadow, radius, or transition
timing in a Blade view. Always reference a token (`var(--…)`) or a utility class below. When a
new pattern is needed, add a token/class to `app.scss` first, then use it.

### Color palette (CSS custom properties)

| Purpose | Token | Value |
|---------|-------|-------|
| Primary (brand) | `--osms-primary` | `#004f75` deep optical blue |
| Primary hover/active | `--osms-primary-hover` | `#00405f` |
| Primary soft surface | `--osms-primary-soft` | `#e7eef4` |
| Page background | `--surface-page` | `#f4f6f9` cool off-white |
| Card surface | `--surface-card` | `#ffffff` pure white |
| Sunken (wells, table heads, kanban) | `--surface-sunken` | `#eef1f5` |
| Sidebar | `--osms-sidebar-bg` | `#f0f3f7` |
| Foreground text | `--osms-fg` | `#1c2733` |
| Muted text | `--osms-muted` | `#6b7785` |
| Faint / placeholder | `--osms-faint` | `#9aa5b1` |
| Hairline border | `--osms-border` | `#e3e8ee` metallic cool |
| Strong border | `--osms-border-strong` | `#d3dae3` |

**Semantic tone pairs** (always use the bg+fg pair together — AA contrast):
`--tone-amber`/`--tone-amber-bg`, `--tone-green`/`--tone-green-bg`,
`--tone-red`/`--tone-red-bg`, `--tone-blue`/`--tone-blue-bg`,
`--tone-neutral`/`--tone-neutral-bg`. Primary accent is the only "luxury" tone for primary
components; tones are for status/semantics only.

### Typography scale

Use these utility classes instead of inline `font-size`:
`.text-3xs` (.62rem) · `.text-2xs` (.68rem) · `.text-xs` (.74rem) · `.text-sm` (.82rem) ·
`.text-md` (.9rem). Tokens: `--text-3xs … --text-base`. Color helpers: `.text-muted-foreground`,
`.text-faint`. Section headers use `.section-label` (uppercase, tracked). Headings use
`.font-display` (tight tracking, weight 600). Body font is Plus Jakarta Sans.

### Shadow hierarchy (elevation = how high a surface floats)

`--shadow-sm` (hairline) → `--shadow-card` (resting cards) → `--shadow-raised` (hover/lifted,
dropdowns triggers) → `--shadow-overlay` (modals, dropdown menus, popovers) →
`--shadow-focus` (focus ring). Never invent a new `box-shadow`; pick the matching tier.

### Radii & spacing

Radii: `--radius-sm` (.45rem) · `--radius` (.625rem) · `--radius-lg` (.9rem) ·
`--radius-xl` (1.1rem) · `--radius-pill`. Spacing rhythm follows a 4pt grid
(`--space-1 … --space-7`); prefer Bootstrap gap/padding utilities at those steps. Page content
wraps in `p-4 p-md-5`; cards use `rounded-4 shadow-sm`; grid gaps `g-3`/`g-4` for breathing room.

### Motion contract (predictable interaction mechanics)

- **One easing curve:** `--ease-spring` = `cubic-bezier(0.16, 1, 0.3, 1)` for interaction/entrance;
  `--ease-out` for micro feedback.
- **Durations:** `--duration-fast` (.15s) hover tints · `--duration-base` (.3s) interactions ·
  `--duration-slow` (.45s) entrances.
- **Identical behavior everywhere:** all buttons, links, inputs, rows, badges, dropdown/list
  items transition with `all var(--duration-base) var(--ease-spring)` (set globally — don't
  re-declare per element).
- **Active press:** clickable elements scale to `0.98` on `:active` (global rule).
- **Focus:** keyboard focus shows a 2px primary outline that expands `outline-offset` 2→3px;
  inputs get `--shadow-focus`. Never remove focus outlines.
- **Entrance:** page content is wrapped in `.page-enter` (fade-up) by `layouts/app.blade.php`;
  use `.animate-fade-up` for ad-hoc slide-in sections. `prefers-reduced-motion` is respected globally.

### [LIQUID MOTION STANDARD] — the bar for every page & element

**Non-negotiable:** every OSMS surface must feel absolutely stunning and premium — the tactile,
liquid fluidity of Apple iOS. This is the acceptance bar for any new view, module, or feature,
not an optional polish pass. Build to it from the first commit.

- **Nothing toggles on/off.** State changes are never abrupt. An element must never pop, snap, or
  hard-swap between shown/hidden, active/inactive, or one value and the next. Everything eases
  (`--ease-spring`) between states — fade, scale, slide, or height-reveal. Treat an instant
  `display` flip as a bug.
- **Animate the whole page and every element in it.** Page sections enter with `.page-enter` /
  `.animate-fade-up`; lists cascade in with `.stagger` (staggered `fade-up`); rows, chips,
  avatars, prices, chevrons all have their own hover/enter/press micro-motion. Reach for the
  liquid primitives already built: `.stagger`, `.skeleton` (shimmer loading, never a bare
  spinner-and-blank), `.reveal`/`.reveal-open` (height-animated show/hide instead of `x-show`
  snapping), `.segmented` (sliding-highlight toggle), the global `.modal` scale-in entrance.
- **Elegant hover on everything interactive.** Rows tint to `--osms-primary-soft`, lift or nudge
  a sub-pixel, and reveal a chevron that slides right; avatars scale `1.06`; the motion is
  identical everywhere so the whole app feels like one instrument.
- **Live, not round-trip.** List/search pages filter live via Alpine + a JSON endpoint (debounced
  ~220ms) — results update as the user types, with a `.skeleton` state, staggered result
  entrance, and URL kept in sync via `history.replaceState`. Full-page GET reloads for search are
  not acceptable. **Reference implementations: `customers/index.blade.php` and
  `inventory/index.blade.php` — copy their structure for any new list page.**
- **Reduced motion is honored** globally; never fight it.

### Component classes (reuse, don't reinvent)

- **Cards:** `.card card-lift rounded-4 shadow-sm` — lifts `-3px` with `--shadow-raised` on hover.
- **KPI stats:** `.osms-stat` (clickable card) + `.osms-stat-icon` with a tone modifier
  (`.osms-stat-icon-amber|green|red|blue|neutral`). `.osms-stat-active` for the selected filter.
- **Status pills:** `.osms-badge` + `.osms-badge-dot` + tone (`.osms-badge-amber|blue|green|red`).
- **Metric cards:** `<x-metric-card tone="primary|amber|default">`.
- **Sidebar:** `.sidebar-link` — hover = light primary tint; `.active` = soft surface + 3px left
  accent + weight 600 (hover and active are deliberately distinct location anchors).
- **Glass:** `.glass` / `.glass-subtle` for floating/auth panels. `.bg-spotlight` for auth/marketing.
- **Tables/lists:** `.osms-orders-table` rows + `.list-group-item-action` + `.search-result-item`
  all hover to `--osms-primary-soft`.

### Buttons

`.btn-primary` = brand fill with depth shadow + darker hover. `.btn-secondary`/`.btn-light` =
white surface, metallic border, sunken hover (high contrast neutral). Keep brand fill for the
single primary action per view; everything else is secondary/light.
