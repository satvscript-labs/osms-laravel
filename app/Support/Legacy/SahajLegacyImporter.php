<?php

namespace App\Support\Legacy;

use Illuminate\Support\Carbon;

/**
 * The brain of the Sahaj Optical legacy migration: turns two unrelated legacy
 * tables into a set of proposed customer profiles + eye records.
 *
 * Pure logic — it reads arrays and returns arrays, touching no database. That
 * keeps every judgement call below independently testable, and lets the command
 * produce a full dry-run report without opening a transaction.
 *
 * The rules encoded here were derived from analysing the real 7,877-row dataset,
 * not assumed. See _artifacts/FirstCustomerFiles/MIGRATION_PLAN.md.
 */
class SahajLegacyImporter
{
    /** Names that are bookkeeping artefacts, not people. */
    private const JUNK_WORDS = [
        'cash', 'case', 'casse', 'casa', 'cashh', 'cse', 'cae', 'caseh', 'chas',
        'old', 'old payment', 'oldpayment', 'bhul', 'test', 'testing', 'abc',
        'ase', 'xyz', 'na', 'n/a', 'nil', 'none', 'unknown', 'lens', 'frame',
        'ffff', 'fffe', 'vxcas', 'dummy', 'sample', 'x',
    ];

    /**
     * `checkedby` values starting with these mean the customer brought their own
     * prescription from elsewhere — not a missing value. Confirmed with the shop
     * owner; stored as "Self" rather than the literal legacy string.
     */
    private const SELF_PREFIXES = ['old', '0ld', 'olf', 'oldd'];

    /** @var list<array<string,mixed>> */
    public array $profiles = [];

    /** @var list<array<string,mixed>> */
    public array $manualReview = [];

    /** @var list<array<string,mixed>> */
    public array $excluded = [];

    /** @var list<array<string,mixed>> */
    public array $autoPickedPhone = [];

    /** @var array<string,int> */
    public array $stats = [];

    /**
     * @param  list<array<string,string|null>>  $eyeRows      raw `eyerecourd` rows
     * @param  list<array<string,string|null>>  $estimateRows raw `estimatebook` rows
     */
    public function __construct(
        private array $eyeRows,
        private array $estimateRows,
    ) {}

    public function analyse(): self
    {
        $records = array_merge(
            $this->collectEyeRecords(),
            $this->collectEstimateIdentities(),
        );

        $this->groupIntoProfiles($records);
        $this->buildStats();

        return $this;
    }

    // ------------------------------------------------------------------ intake

    /**
     * Every `eyerecourd` row is both an identity signal and a prescription.
     *
     * @return list<array<string,mixed>>
     */
    private function collectEyeRecords(): array
    {
        $out = [];
        $seenFingerprints = [];

        foreach ($this->eyeRows as $row) {
            $name = self::cleanName($row['name'] ?? '');
            $phone = self::normalisePhone($row['contectno'] ?? '');
            $date = self::parseDate($row['date'] ?? null);

            if ($reason = self::junkReason($name, $phone !== null)) {
                $this->excluded[] = [
                    'source' => 'eyerecourd',
                    'source_id' => $row['id'] ?? '',
                    'name' => (string) ($row['name'] ?? ''),
                    'phone' => (string) ($row['contectno'] ?? ''),
                    'date' => $date?->toDateString() ?? '',
                    'reason' => $reason,
                ];

                continue;
            }

            // Same person, same day, same prescription = an accidental re-submit.
            $fingerprint = $name . '|' . $phone . '|' . ($date?->toDateString() ?? '') . '|'
                . ($row['lspl'] ?? '') . '|' . ($row['rspl'] ?? '');
            if (isset($seenFingerprints[$fingerprint])) {
                $this->excluded[] = [
                    'source' => 'eyerecourd',
                    'source_id' => $row['id'] ?? '',
                    'name' => $name,
                    'phone' => $phone ?? '',
                    'date' => $date?->toDateString() ?? '',
                    'reason' => 'Duplicate of an identical earlier row (same name, phone, date and prescription)',
                ];

                continue;
            }
            $seenFingerprints[$fingerprint] = true;

            $out[] = [
                'name' => $name,
                'phone' => $phone,
                'date' => $date,
                'source' => 'eyerecourd',
                'source_id' => (string) ($row['id'] ?? ''),
                'eye_record' => $this->mapPrescription($row, $date),
            ];
        }

        return $out;
    }

    /**
     * `estimatebook` contributes identity only — no orders, amounts or stock are
     * migrated (confirmed scope). It matters because some people bought glasses
     * without ever being eye-tested here, so they exist nowhere else.
     *
     * @return list<array<string,mixed>>
     */
    private function collectEstimateIdentities(): array
    {
        $out = [];

        foreach ($this->estimateRows as $row) {
            $name = self::cleanName($row['first_name'] ?? '');
            $phone = self::normalisePhone($row['contact'] ?? '');
            $date = self::parseDate($row['date'] ?? null);
            $total = (float) ($row['total'] ?? 0);

            // A real sale is never zero or negative — those rows are cash
            // corrections filed under a pseudo-name, not customers.
            $reason = $total <= 0
                ? 'Non-positive total (a bookkeeping correction, not a sale)'
                : self::junkReason($name, $phone !== null);

            if ($reason) {
                $this->excluded[] = [
                    'source' => 'estimatebook',
                    'source_id' => $row['order_no'] ?? '',
                    'name' => (string) ($row['first_name'] ?? ''),
                    'phone' => (string) ($row['contact'] ?? ''),
                    'date' => $date?->toDateString() ?? '',
                    'reason' => $reason,
                ];

                continue;
            }

            $out[] = [
                'name' => $name,
                'phone' => $phone,
                'date' => $date,
                'source' => 'estimatebook',
                'source_id' => (string) ($row['order_no'] ?? ''),
                'eye_record' => null,
            ];
        }

        return $out;
    }

    // ------------------------------------------------------- identity grouping

    /**
     * Group raw records into proposed profiles.
     *
     * Identity is keyed on phone where one exists (reliable), falling back to the
     * normalised name. Within a phone, people are separated by FIRST NAME TOKEN:
     * `AMIT TRILOCHAN SHARMA` and `AMIT SHARMA` are one person, but `DHARAM
     * KHAPRA` and `KAVYA KHAPRA` on a shared family phone are two — merging them
     * would put one person's prescription in another's file.
     *
     * @param  list<array<string,mixed>>  $records
     */
    private function groupIntoProfiles(array $records): void
    {
        $n = count($records);
        $parent = range(0, max(0, $n - 1));

        $find = function (int $i) use (&$parent, &$find): int {
            while ($parent[$i] !== $i) {
                $parent[$i] = $parent[$parent[$i]]; // path compression
                $i = $parent[$i];
            }

            return $i;
        };
        $union = function (int $a, int $b) use (&$parent, $find): void {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$rb] = $ra;
            }
        };

        // Two records describe the same person when EITHER holds:
        //   (a) the exact same full name — a person who changed their number, or
        //       who appears in one table with a phone and the other without;
        //   (b) the same phone AND the same first name — spelling or middle-name
        //       variants of one person (AMIT SHARMA / AMIT TRILOCHAN SHARMA).
        // Crucially NOT "same phone" alone: a household number legitimately
        // covers several different people, and merging them would file one
        // person's prescription under another's name.
        $byName = [];
        $byPhoneAndFirstName = [];

        foreach ($records as $i => $record) {
            $byName[$record['name']][] = $i;

            if ($record['phone'] !== null) {
                $key = $record['phone'] . '|' . self::firstToken($record['name']);
                $byPhoneAndFirstName[$key][] = $i;
            }
        }

        foreach ([$byName, $byPhoneAndFirstName] as $buckets) {
            foreach ($buckets as $indexes) {
                for ($k = 1, $count = count($indexes); $k < $count; $k++) {
                    $union($indexes[0], $indexes[$k]);
                }
            }
        }

        /** @var array<int,list<array<string,mixed>>> $components */
        $components = [];
        foreach ($records as $i => $record) {
            $components[$find($i)][] = $record;
        }

        $this->resolveComponents(array_values($components));
    }

    /**
     * Turn each resolved person into a profile, settling contests for a shared
     * household number along the way.
     *
     * `customers` is unique on (tenant, phone), so when several people share one
     * number only one row can hold it. Rather than drop the rest — which would
     * have discarded ~1,100 real people — the most recently seen person keeps
     * the number (they are the likeliest current owner) and the others are
     * imported with a placeholder, flagged for the front desk to collect a real
     * number on the next visit. Nobody is lost, and no prescription is misfiled.
     *
     * @param  list<list<array<string,mixed>>>  $components
     */
    private function resolveComponents(array $components): void
    {
        $people = [];

        foreach ($components as $rows) {
            usort($rows, static fn ($a, $b) => ($b['date']?->timestamp ?? 0) <=> ($a['date']?->timestamp ?? 0));

            $newest = $rows[0];
            $phones = array_values(array_unique(array_filter(array_column($rows, 'phone'))));

            // A person seen under several numbers keeps the most recent one.
            if (count($phones) > 1) {
                $this->autoPickedPhone[] = [
                    'name' => $newest['name'],
                    'chosen_phone' => $newest['phone'] ?? ($phones[0] ?? ''),
                    'chosen_from_date' => $newest['date']?->toDateString() ?? '',
                    'other_phones_on_record' => implode(', ', array_diff($phones, [$newest['phone']])),
                    'note' => 'Most recent record wins; staff can correct it in-app.',
                ];
            }

            $people[] = [
                'rows' => $rows,
                'newest' => $newest,
                'preferred_phone' => $newest['phone'] ?? ($phones[0] ?? null),
                'last_ts' => $newest['date']?->timestamp ?? 0,
            ];
        }

        // Settle contests for the same number: newest visit wins it.
        $claims = [];
        foreach ($people as $i => $person) {
            if ($person['preferred_phone'] !== null) {
                $claims[$person['preferred_phone']][] = $i;
            }
        }
        $winner = [];
        foreach ($claims as $phone => $indexes) {
            usort($indexes, static fn ($a, $b) => $people[$b]['last_ts'] <=> $people[$a]['last_ts']);
            $winner[$phone] = $indexes[0];
        }

        foreach ($people as $i => $person) {
            $phone = $person['preferred_phone'];
            $contested = $phone !== null && count($claims[$phone]) > 1;
            $keepsPhone = $phone !== null && $winner[$phone] === $i;

            $this->buildProfile($person, $keepsPhone, $contested, $people, $claims);
        }
    }

    /**
     * @param  array<string,mixed>  $person
     * @param  list<array<string,mixed>>  $people
     * @param  array<string,list<int>>  $claims
     */
    private function buildProfile(array $person, bool $keepsPhone, bool $contested, array $people, array $claims): void
    {
        $rows = $person['rows'];
        $newest = $person['newest'];
        $eyeRecords = array_values(array_filter(array_column($rows, 'eye_record')));

        $profile = [
            'name' => $newest['name'],
            'name_variants' => array_values(array_unique(array_column($rows, 'name'))),
            'phone' => $keepsPhone ? $person['preferred_phone'] : null,
            'shares_phone_with' => '',
            'first_seen' => self::earliest($rows)?->toDateString() ?? '',
            'last_seen' => $newest['date']?->toDateString() ?? '',
            'source_rows' => count($rows),
            'eye_records' => $eyeRecords,
            'eye_record_count' => count($eyeRecords),
            'sources' => implode(', ', array_unique(array_column($rows, 'source'))),
            'tier' => $keepsPhone ? 1 : 2,
        ];

        if ($contested) {
            $others = [];
            foreach ($claims[$person['preferred_phone']] as $j) {
                if ($people[$j]['newest']['name'] !== $newest['name']) {
                    $others[] = $people[$j]['newest']['name'];
                }
            }
            $profile['shares_phone_with'] = implode(', ', $others);

            // Everyone on a shared number is surfaced for review — including the
            // one who kept it — so the shop can confirm the number sits on the
            // right person and collect fresh numbers for the rest.
            $this->manualReview[] = $profile + [
                'contested_phone' => $person['preferred_phone'],
                'outcome' => $keepsPhone
                    ? 'KEEPS the number (most recent visit)'
                    : 'Imported with a placeholder — needs its own number',
            ];
        }

        $this->profiles[] = $profile;
    }

    // ----------------------------------------------------------- field mapping

    /**
     * Map a legacy prescription row onto the new schema.
     *
     * Verified against the full dataset: `sph + add = nv` held in 1,316 of 1,316
     * checkable rows, which is what confirms leftspl/rightspl are near-vision
     * values rather than a second sphere reading.
     *
     * @param  array<string,string|null>  $row
     * @return array<string,mixed>
     */
    private function mapPrescription(array $row, ?Carbon $date): array
    {
        return [
            // OS = left eye (legacy "l"), OD = right eye (legacy "r").
            'os_sph' => self::decimal($row['lspl'] ?? null),
            'os_cyl' => self::decimal($row['lcly'] ?? null),
            'os_axis' => self::integer($row['laxis'] ?? null),
            'os_add' => self::decimal($row['leftadd'] ?? null),
            'os_va' => self::text($row['lvs'] ?? null),
            'os_nv' => self::decimal($row['leftspl'] ?? null),
            'od_sph' => self::decimal($row['rspl'] ?? null),
            'od_cyl' => self::decimal($row['rcly'] ?? null),
            'od_axis' => self::integer($row['raxis'] ?? null),
            'od_add' => self::decimal($row['rightadd'] ?? null),
            'od_va' => self::text($row['rvs'] ?? null),
            'od_nv' => self::decimal($row['rightspl'] ?? null),
            'checked_by' => self::mapCheckedBy($row['checkedby'] ?? null),
            'date' => $date,
        ];
    }

    /**
     * "OLD" (and its many typo variants) means the customer supplied their own
     * prescription — stored as "Self". Everything else is a real clinic or
     * optometrist name and is kept verbatim.
     */
    public static function mapCheckedBy(?string $raw): ?string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }

        $lower = strtolower($value);
        foreach (self::SELF_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return 'Self';
            }
        }

        return $value;
    }

    // --------------------------------------------------------------- utilities

    /** Collapse whitespace and upper-case, so `Rushi  Patel` == `RUSHI PATEL`. */
    public static function cleanName(?string $raw): string
    {
        $name = preg_replace('/\s+/', ' ', trim((string) $raw)) ?? '';

        // Strip stray punctuation that came from typos (e.g. `CHAND JAYESH M,AKADIYA`).
        return strtoupper(trim($name, " \t\n\r\0\x0B.,-"));
    }

    public static function firstToken(string $name): string
    {
        $parts = array_values(array_filter(explode(' ', $name)));

        return $parts[0] ?? '';
    }

    /**
     * Returns the reason a name is not a real person, or null if it looks valid.
     *
     * $hasPhone tightens the fuzzy rule below: a real customer almost always has
     * a phone on file, so a cash-typo lookalike WITH a valid number is given the
     * benefit of the doubt rather than silently dropped.
     */
    public static function junkReason(string $name, bool $hasPhone = false): ?string
    {
        if ($name === '') {
            return 'Blank name';
        }
        if (mb_strlen($name) <= 2) {
            return 'Name is 2 characters or fewer';
        }
        if (preg_match('/^[\d\s\-\+]+$/', $name)) {
            return 'Name is numeric (a phone number or amount typed into the name field)';
        }
        if (in_array(strtolower($name), self::JUNK_WORDS, true)) {
            return 'Bookkeeping placeholder, not a customer name';
        }

        // The shop logged thousands of walk-in cash sales under the pseudo-name
        // "CASE"/"CASH", fat-fingered a different way nearly every time (CASE3,
        // CASHHH, CASWE, XAS, CAWE…). An exact word list can't keep up, but real
        // names never sit 1-2 edits from "cash" — VEDANT, VIVEK, KAJAL and VIJAY
        // are all 4+ edits away, so they survive this check.
        if (! $hasPhone && ! str_contains($name, ' ') && mb_strlen($name) <= 7) {
            foreach (['cash', 'case', 'cas'] as $target) {
                if (levenshtein(strtolower($name), $target) <= 2) {
                    return 'Cash-entry typo variant (no phone on record)';
                }
            }
        }

        return null;
    }

    /**
     * A legacy number is a bare 10-digit national number. Anything else — blank,
     * the habitual `0000000000` placeholder, a truncated 9-digit entry, or an
     * 11-digit typo — is treated as "no phone" rather than guessed at.
     */
    public static function normalisePhone(?string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $raw) ?? '';

        if (strlen($digits) !== 10) {
            return null;
        }
        if (! preg_match('/^[6-9]/', $digits)) {
            return null; // Indian mobile numbers start 6-9; 000… etc. are placeholders.
        }

        return '+91 ' . $digits;
    }

    private static function parseDate(?string $raw): ?Carbon
    {
        $value = trim((string) $raw);
        if ($value === '' || str_starts_with($value, '0000')) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private static function earliest(array $rows): ?Carbon
    {
        $dates = array_filter(array_column($rows, 'date'));
        if ($dates === []) {
            return null;
        }

        return collect($dates)->sortBy(fn (Carbon $d) => $d->timestamp)->first();
    }

    private static function decimal(?string $raw): ?float
    {
        $value = trim((string) $raw);
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private static function integer(?string $raw): ?int
    {
        $value = trim((string) $raw);
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private static function text(?string $raw): ?string
    {
        $value = trim((string) $raw);

        return $value === '' ? null : mb_substr($value, 0, 20);
    }

    private function buildStats(): void
    {
        $tier1 = array_filter($this->profiles, fn ($p) => $p['tier'] === 1);
        $tier2 = array_filter($this->profiles, fn ($p) => $p['tier'] === 2);

        $this->stats = [
            'source_eye_rows' => count($this->eyeRows),
            'source_estimate_rows' => count($this->estimateRows),
            'excluded_rows' => count($this->excluded),
            'profiles_to_import' => count($this->profiles),
            'with_real_phone' => count($tier1),
            'with_placeholder_phone' => count($tier2),
            'flagged_for_review' => count($this->manualReview),
            'eye_records_to_import' => array_sum(array_column($this->profiles, 'eye_record_count')),
            'phone_auto_picked' => count($this->autoPickedPhone),
            'merged_name_variants' => count(array_filter($this->profiles, fn ($p) => count($p['name_variants']) > 1)),
        ];
    }
}
