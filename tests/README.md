# OSMS test suite

```bash
php artisan test                        # full suite (SQLite :memory:)
php artisan test --filter=Phase54       # one phase
php artisan test -c phpunit.mysql.xml   # against MariaDB — see below
```

## Phase numbering (TEST-05)

Test classes are named `PhaseNN<Feature>Test`. **`NN` is a feature label, not a
chronological one.** `Phase32`–`35` were authored *after* `Phase36`/`37`, so the numbers
no longer track git history — and renumbering them now would rewrite file names
referenced across `_artifacts/audit/*` for no functional gain.

Treat `NN` as a stable identifier for a feature area. When adding a suite, take the next
free number; don't renumber existing ones.

## Conventions

- **Tenant isolation is mandatory.** Any suite covering tenant-owned data must assert that
  another tenant's records are invisible (`BelongsToTenant` / `TenantScope`). See
  `Phase57TrashLiveSearchTest::test_archive_search_stays_within_the_tenant`.
- **Real routes and a real DB.** Tests hit actual endpoints rather than mocking; the
  WhatsApp gateway is swapped via the container binding (log driver), not a mock, so the
  code path under test is the production one.
- **Models are created longhand.** Only `UserFactory` exists. Verbose but explicit — keep it
  that way unless a factory earns its place.

## Timezone (TEST-04)

`phpunit.xml` pins `APP_TIMEZONE=UTC`, deliberately **different** from
`config('billing.timezone')` (`Asia/Kolkata`). That divergence is what surfaces
UTC-vs-IST day-boundary bugs — it is how the `Phase52` trial-reminder flake was caught.
Don't "align" them.

When asserting on dates, build fixtures in the *same* timezone the code under test uses,
rather than a bare `now()`.

## Running against MariaDB (TEST-02)

SQLite silently no-ops `lockForUpdate()`, relaxes the `orders`/`subscriptions` ENUMs, and
never executes the MySQL `DATE_FORMAT` branch of `Customer::scopeUpcomingBirthday()`. A
green SQLite run therefore does not prove correctness on production.

```bash
docker run -d --name osms-mariadb -p 3307:3306 \
  -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=osms_test mariadb:10.11
php artisan test -c phpunit.mysql.xml
```

Full findings: `_artifacts/audit/11_MYSQL_VERIFICATION.md`.
