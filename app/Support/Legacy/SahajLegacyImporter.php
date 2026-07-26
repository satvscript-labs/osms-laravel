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
        // Cash-entry typos that don't begin "ca", so the anchored fuzzy rule in
        // looksLikeCashEntry() can't reach them. Observed in the real dump.
        'xas', 'czse',
    ];

    /**
     * Stock and service lines the shop filed under the name field ("CASH LENSE
     * SPRAY", "RB & SV GOGGLES", "LENSE SOLUTION"). Matched as whole tokens
     * anywhere in the name — no Indian given name collides with any of these.
     */
    private const PRODUCT_WORDS = [
        'lens', 'lense', 'lenses', 'goggle', 'goggles', 'frame', 'frames',
        'rayban', 'spray', 'solution', 'sale', 'cover', 'chasma', 'chashma',
        'cloth', 'nosepad', 'screw', 'stock', 'repair', 'oneday',
    ];

    /**
     * Payment-mode notes typed where a name belongs ("OLD 06/12/23",
     * "ONLINE 18/12/233"). Only ever matched as the FIRST token.
     */
    private const MODE_WORDS = [
        'old', 'online', 'olnine', 'onlin', 'onl', 'gpay', 'phonepe', 'paytm',
        'upi', 'card', 'credit', 'debit', 'due', 'pending', 'balance', 'advance',
    ];

    /**
     * `checkedby` values starting with these mean the customer brought their own
     * prescription from elsewhere — not a missing value. Confirmed with the shop
     * owner; stored as "Self" rather than the literal legacy string.
     */
    private const SELF_PREFIXES = ['old', '0ld', 'olf', 'oldd'];

    /** A gap only the shop can close — shown as a suffix on the customer name. */
    public const MARKER_ACTION = '[Action needed]';

    /** Context, not a gap: this person is reachable on a relative's number. */
    public const MARKER_SHARED = '[Shared number]';

    /** @var list<array<string,mixed>> */
    public array $profiles = [];

    /** @var list<array<string,mixed>> */
    public array $manualReview = [];

    /** @var list<array<string,mixed>> */
    public array $excluded = [];

    /** @var list<array<string,mixed>> */
    public array $autoPickedPhone = [];

    /** Profiles dropped for holding neither a phone nor an eye test. */
    /** @var list<array<string,mixed>> */
    public array $lowPriority = [];

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
        $this->dropLowPriority();
        $this->buildStats();

        return $this;
    }

    /**
     * Drop profiles that carry no usable information at all.
     *
     * Confirmed with the shop owner: a profile is worth keeping if it is either
     * CONTACTABLE (a number was recorded for them somewhere) or CLINICALLY
     * USEFUL (it holds at least one eye test). One of the two is enough. A
     * profile with neither is a bare name typed on an estimate — there is
     * nothing to call, nothing to prescribe from, and no way to tell whether it
     * was even a person.
     *
     * Runs AFTER grouping, deliberately: a name that looks empty on its own may
     * pick up a phone or a prescription once merged with the same person's other
     * rows, and must be judged on the merged result.
     */
    private function dropLowPriority(): void
    {
        $keep = [];

        foreach ($this->profiles as $profile) {
            // had_phone_on_record, NOT phone: someone who lost a shared household
            // number to a more recent visitor still had a number on file, and is
            // contactable via the family. Deleting them would be the one
            // genuinely destructive reading of this rule.
            if ($profile['had_phone_on_record'] || $profile['eye_record_count'] > 0) {
                $keep[] = $profile;

                continue;
            }

            $this->lowPriority[] = [
                'name' => $profile['name'],
                'name_variants' => implode(' / ', $profile['name_variants']),
                'first_seen' => $profile['first_seen'],
                'last_seen' => $profile['last_seen'],
                'source_rows' => $profile['source_rows'],
                'sources' => $profile['sources'],
                'reason' => 'No phone number and no eye test on record — nothing to contact or prescribe from',
            ];
        }

        $this->profiles = $keep;

        // A dropped person may have been the only one contesting a number, so
        // anyone still flagged for a shared phone must actually still exist.
        $names = array_column($this->profiles, 'name');
        $this->manualReview = array_values(array_filter(
            $this->manualReview,
            static fn ($r) => in_array($r['name'], $names, true),
        ));
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

        $phone = $keepsPhone ? $person['preferred_phone'] : null;

        // Whether a number was ever recorded for this person ANYWHERE in the
        // legacy data — distinct from `$phone`, which is null when they lost a
        // shared-household number to a more recent visitor. The low-priority
        // rule below must read this one, or it would delete people who did have
        // a number and merely lost the contest for it.
        $hadPhoneOnRecord = $person['preferred_phone'] !== null;

        $isPatient = $eyeRecords !== [];
        $flag = self::markerFor($newest['name'], $phone !== null, $hadPhoneOnRecord, $isPatient);

        $profile = [
            'name' => $newest['name'],
            'name_variants' => array_values(array_unique(array_column($rows, 'name'))),
            'phone' => $phone,
            'had_phone_on_record' => $hadPhoneOnRecord,
            'is_patient' => $isPatient,
            'marker' => $flag['marker'] ?? null,
            'marker_reason' => $flag['reason'] ?? '',
            'needs_action' => ($flag['marker'] ?? null) === self::MARKER_ACTION,
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
        // Everything below is a heuristic about what a name LOOKS like, and any
        // heuristic will eventually be wrong about a real person. A valid phone
        // number is hard evidence to the contrary — the shop only bothered
        // typing one for an actual customer — so it overrides all of them. Such
        // rows are imported and flagged instead (see actionReason()), which is
        // the recoverable outcome: staff can fix a bad name, not a deleted row.
        if ($hasPhone) {
            return null;
        }

        if (in_array(strtolower($name), self::JUNK_WORDS, true)) {
            return 'Bookkeeping placeholder, not a customer name';
        }
        if (self::looksLikeCashEntry($name)) {
            return 'Cash-entry pseudo-name (no phone on record)';
        }
        if ($word = self::productWordIn($name)) {
            return "Stock/service line, not a person (contains \"{$word}\")";
        }
        if (self::firstTokenIsPaymentMode($name)) {
            return 'Payment-mode note typed into the name field';
        }
        if (preg_match('#\d{1,2}[/\-.]\d{1,2}([/\-.]\d{2,4})?#', $name)) {
            return 'A date was typed into the name field';
        }
        if ($reason = self::gibberishReason($name)) {
            return $reason;
        }

        return null;
    }

    /**
     * The suffix an imported profile carries in the app, or null if it's clean.
     *
     * Two markers, deliberately distinct:
     *
     *   [Action needed]  a real gap only the shop can close. Reserved for
     *                    PATIENTS without their own number — a file with eye
     *                    tests in it is the one thing the shop cannot afford to
     *                    lose track of, so it gets the loud marker even when a
     *                    relative's number is on file — and for a suspect name
     *                    that survived only because a real number is attached.
     *
     *   [Shared number]  not a gap, just context: a non-patient reachable on a
     *                    household number a relative holds. Without it the
     *                    placeholder `+91 0…` would appear on screen with no
     *                    explanation, which reads like a bug.
     *
     * @return array{marker:string,reason:string}|null
     */
    public static function markerFor(
        string $name,
        bool $hasOwnPhone,
        bool $hadPhoneOnRecord = false,
        bool $isPatient = false,
    ): ?array {
        if (! $hasOwnPhone) {
            if ($isPatient) {
                return [
                    'marker' => self::MARKER_ACTION,
                    'reason' => $hadPhoneOnRecord
                        ? 'Has eye tests on file, but a relative holds the shared number — collect their own'
                        : 'Has eye tests on file but no phone number anywhere — collect one',
                ];
            }

            if ($hadPhoneOnRecord) {
                return [
                    'marker' => self::MARKER_SHARED,
                    'reason' => 'Reachable on a household number a relative holds',
                ];
            }

            // Unreachable in practice — dropLowPriority() removes anyone with
            // neither a number nor an eye test — but kept so the rule is total.
            return ['marker' => self::MARKER_ACTION, 'reason' => 'No phone number on record'];
        }

        // It kept its real phone, so junkReason() waved it through. The name
        // still reads like a bookkeeping entry, and only the shop can say who
        // this actually is.
        if (in_array(mb_strtolower($name), self::JUNK_WORDS, true)
            || self::looksLikeCashEntry($name)
            || self::productWordIn($name) !== null
            || self::firstTokenIsPaymentMode($name)
            || self::gibberishReason($name) !== null) {
            return [
                'marker' => self::MARKER_ACTION,
                'reason' => 'Name looks like a bookkeeping entry, but a real phone number is attached',
            ];
        }

        return null;
    }

    /**
     * The shop logged walk-in cash sales under the pseudo-name "CASE"/"CASH",
     * fat-fingered differently nearly every time (CASE SALE, CASHH GOGGELS,
     * CASEB LENS, CASE 8/1/24).
     *
     * The match is anchored on the leading "ca" before the edit-distance test.
     * That anchor is doing real work: without it, distance alone sweeps up a
     * long list of genuine Gujarati names — JAY, YASH, HARSH, DAKSH, VASU, RAJ,
     * ANSH, VANSH, DARSH, PAL, ASHA, RAM, DAX are every one of them within two
     * edits of "cas". None of them begin "ca", so the anchor keeps them.
     */
    public static function looksLikeCashEntry(string $name): bool
    {
        $token = self::normaliseToken(self::firstToken($name));

        if ($token === '' || mb_strlen($token) > 8) {
            return false;
        }
        if (! preg_match('/^[ck][a@oz]/', $token)) {
            return false;
        }

        foreach (['cash', 'case', 'cas'] as $target) {
            if (levenshtein($token, $target) <= 2) {
                return true;
            }
        }

        return false;
    }

    /** Returns the offending product word, or null. */
    public static function productWordIn(string $name): ?string
    {
        foreach (self::tokens($name) as $token) {
            if (in_array($token, self::PRODUCT_WORDS, true)) {
                return $token;
            }
            // "GOGGELS", "GOGALSE", "GOGEELS", "GOGLES" — one misspelling per
            // entry. Anchored on "gog" so it can't reach a real name.
            if (str_starts_with($token, 'gog') && levenshtein($token, 'goggles') <= 3) {
                return $token;
            }
        }

        return null;
    }

    public static function firstTokenIsPaymentMode(string $name): bool
    {
        return in_array(self::normaliseToken(self::firstToken($name)), self::MODE_WORDS, true);
    }

    /**
     * Keyboard mash typed to get past a required field (VXSSDH, DJHJD, WWWW).
     *
     * Deliberately conservative — only two signals, both of which no real name
     * can produce. Softer gibberish (CSADA, CADWD) is left alone and imported
     * with an "[Action needed]" flag rather than risk deleting a real person.
     */
    public static function gibberishReason(string $name): ?string
    {
        if (str_contains($name, ' ')) {
            return null;   // multi-word entries are almost always real
        }

        $token = self::normaliseToken($name);
        if (mb_strlen($token) < 3) {
            return null;
        }

        if (preg_match('/(.)\1{2,}/', $token)) {
            return 'Keyboard mash — the same letter three or more times in a row';
        }
        if (! preg_match('/[aeiou]/', $token)) {
            return 'Keyboard mash — no vowels at all';
        }

        return null;
    }

    /** @return list<string> lower-cased word tokens, punctuation stripped */
    private static function tokens(string $name): array
    {
        $parts = preg_split('/[\s\/\-&,+.]+/', mb_strtolower($name)) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $t): string => self::normaliseToken($t),
            $parts
        )));
    }

    private static function normaliseToken(string $token): string
    {
        return trim(mb_strtolower($token), " \t.,\-/&+");
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
            'patients' => count(array_filter($this->profiles, fn ($p) => $p['is_patient'])),
            'needs_action' => count(array_filter($this->profiles, fn ($p) => $p['needs_action'])),
            'shared_number' => count(array_filter(
                $this->profiles,
                fn ($p) => $p['marker'] === self::MARKER_SHARED
            )),
            'patients_needing_a_number' => count(array_filter(
                $this->profiles,
                fn ($p) => $p['is_patient'] && $p['phone'] === null
            )),
            'kept_despite_odd_name' => count(array_filter(
                $this->profiles,
                fn ($p) => $p['needs_action'] && $p['phone'] !== null
            )),
            'low_priority_dropped' => count($this->lowPriority),
        ];
    }
}
