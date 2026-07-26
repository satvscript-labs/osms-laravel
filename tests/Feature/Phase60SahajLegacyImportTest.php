<?php

namespace Tests\Feature;

use App\Support\Legacy\SahajLegacyImporter;
use App\Support\Legacy\SqlDumpParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sahaj Optical legacy migration — identity resolution rules.
 *
 * These are the decisions with real-world consequences: merging two people would
 * file one person's prescription under another's name, and over-filtering would
 * silently discard a real customer. Every rule here was derived from the actual
 * 7,877-row dataset, so the tests use its real shapes (shared family numbers,
 * "CASE" cash rows, `0000000000` placeholders, middle-name variants).
 */
class Phase60SahajLegacyImportTest extends TestCase
{
    // ------------------------------------------------------------ phone rules

    #[DataProvider('phoneProvider')]
    public function test_phone_normalisation(?string $raw, ?string $expected): void
    {
        $this->assertSame($expected, SahajLegacyImporter::normalisePhone($raw));
    }

    public static function phoneProvider(): array
    {
        return [
            'plain 10-digit' => ['9664948286', '+91 9664948286'],
            'starts with 6' => ['6351618016', '+91 6351618016'],
            'the habitual all-zero placeholder' => ['0000000000', null],
            'a bare zero' => ['0', null],
            'nine digits (truncated entry)' => ['987987340', null],
            'eleven digits (fat-fingered)' => ['85113424246', null],
            'landline-style prefix' => ['2812345678', null],
            'blank' => ['', null],
            'null' => [null, null],
        ];
    }

    // ------------------------------------------------------------- junk names

    #[DataProvider('junkProvider')]
    public function test_junk_name_detection(string $name, bool $hasPhone, bool $isJunk): void
    {
        $reason = SahajLegacyImporter::junkReason($name, $hasPhone);

        $isJunk
            ? $this->assertNotNull($reason, "'{$name}' should be rejected")
            : $this->assertNull($reason, "'{$name}' should be kept (got: {$reason})");
    }

    public static function junkProvider(): array
    {
        return [
            // Bookkeeping artefacts the shop filed cash sales under.
            'CASE' => ['CASE', false, true],
            'CASH' => ['CASH', false, true],
            'BHUL (Gujarati for "mistake")' => ['BHUL', false, true],
            'OLD PAYMENT' => ['OLD PAYMENT', false, true],
            // Typo variants an exact word list would miss.
            'CASE3' => ['CASE3', false, true],
            'CASHHH' => ['CASHHH', false, true],
            'CASWE' => ['CASWE', false, true],
            'XAS' => ['XAS', false, true],
            // Real names that sit near those typos must survive.
            'VEDANT' => ['VEDANT', false, false],
            'VIVEK' => ['VIVEK', false, false],
            'KAJAL' => ['KAJAL', false, false],
            'VIJAY' => ['VIJAY', false, false],
            'CHIMAN' => ['CHIMAN', false, false],
            'KRUPA' => ['KRUPA', false, false],
            // Multi-word cash entries — the class that slipped through the first
            // build, because the old rule only looked at single-token names.
            'CASE SALE' => ['CASE SALE', false, true],
            'CASH BETALA' => ['CASH BETALA', false, true],
            'CASHH GOGGELS' => ['CASHH GOGGELS', false, true],
            'CASE 8/1/24' => ['CASE 8/1/24', false, true],
            'CASEB LENS' => ['CASEB LENS', false, true],
            // Stock/service lines filed under the name field.
            'RB & SV GOGGLES' => ['RB & SV GOGGLES', false, true],
            'LENSE SOLUTION' => ['LENSE SOLUTION', false, true],
            'JANKI LENS' => ['JANKI LENS', false, true],
            'GOGALSE (misspelling)' => ['GOGALSE', false, true],
            // Payment-mode notes and bare dates.
            'OLD 06/12/23' => ['OLD 06/12/23', false, true],
            'ONLINE 18/12/233' => ['ONLINE 18/12/233', false, true],
            '2/7/24' => ['2/7/24', false, true],
            // Keyboard mash — only the two unambiguous signals.
            'no vowels at all' => ['VXSSDH', false, true],
            'same letter x4' => ['WWWW', false, true],
            'triple letter' => ['SAAA', false, true],
            // Softer gibberish is deliberately KEPT and flagged, not deleted.
            'CSADA is kept for review' => ['CSADA', false, false],
            // Real Gujarati names that sit within 2 edits of "cas" — the anchor
            // on a leading "ca" is the only thing saving them.
            'JAY' => ['JAY', false, false],
            'YASH' => ['YASH', false, false],
            'HARSH' => ['HARSH', false, false],
            'DAKSH' => ['DAKSH', false, false],
            'VASU' => ['VASU', false, false],
            'RAJ' => ['RAJ', false, false],
            'ANSH' => ['ANSH', false, false],
            'VANSH' => ['VANSH', false, false],
            'DARSH' => ['DARSH', false, false],
            'ASHA' => ['ASHA', false, false],
            'DAX' => ['DAX', false, false],
            // Real names that merely contain a flagged substring, not the token.
            'KISHAN is not "cash"' => ['KISHAN', false, false],
            'FRAMEZ is not "frame"' => ['FRAMEZ', false, false],
            // A phone on record earns the benefit of the doubt — every heuristic
            // yields to it, because a bad name is fixable and a deleted row is not.
            'CADE with a phone' => ['CADE', true, false],
            'CASE SALE with a phone' => ['CASE SALE', true, false],
            'VXSSDH with a phone' => ['VXSSDH', true, false],
            'JANKI LENS with a phone' => ['JANKI LENS', true, false],
            // Structural junk — unusable even with a phone, so still rejected.
            'blank' => ['', false, true],
            'single letter' => ['Q', false, true],
            'two letters' => ['AA', false, true],
            'a phone typed into the name' => ['8866883154', false, true],
            // Ordinary full names.
            'full name' => ['MIHIR BHAVESH CHANDARANA', false, false],
        ];
    }

    // -------------------------------------------------------- name markers

    /**
     * @param  array{0:string,1:bool,2:bool,3:bool}  $args  name, ownPhone, hadPhone, isPatient
     */
    #[DataProvider('markerProvider')]
    public function test_name_marker(array $args, ?string $expected): void
    {
        $marker = SahajLegacyImporter::markerFor(...$args)['marker'] ?? null;

        $this->assertSame($expected, $marker, "marker for '{$args[0]}'");
    }

    public static function markerProvider(): array
    {
        $action = SahajLegacyImporter::MARKER_ACTION;
        $shared = SahajLegacyImporter::MARKER_SHARED;

        return [
            // A PATIENT without their own number always gets the loud marker —
            // even when a relative's number is on file. Confirmed with the owner:
            // these are the records the shop most needs to keep hold of.
            'patient, no number anywhere' => [['ANJALI SHAH', false, false, true], $action],
            'patient on a relative\'s number' => [['ANJALI SHAH', false, true, true], $action],

            // A NON-patient on a relative's number is context, not a to-do.
            'non-patient on a relative\'s number' => [['MOTHER PATEL', false, true, false], $shared],

            // A suspect name kept only because a real number is attached.
            'cash entry with a phone' => [['CASE', true, true, false], $action],
            'stock line with a phone' => [['JANKI LENS', true, true, false], $action],
            'keyboard mash with a phone' => [['VXSSDH', true, true, false], $action],

            // Nothing wrong with these at all — no marker.
            'clean name, own phone' => [['RUSHI PATEL', true, true, false], null],
            'clean patient, own phone' => [['RUSHI PATEL', true, true, true], null],
            'long clean name, own phone' => [['MIHIR BHAVESH CHANDARANA', true, true, false], null],
        ];
    }

    public function test_the_marker_is_appended_to_the_display_name(): void
    {
        $this->assertSame(
            'Rushi Patel [Action needed]',
            \App\Console\Commands\ImportSahajLegacy::displayName('RUSHI PATEL', SahajLegacyImporter::MARKER_ACTION),
        );
        $this->assertSame(
            'Rushi Patel [Shared number]',
            \App\Console\Commands\ImportSahajLegacy::displayName('RUSHI PATEL', SahajLegacyImporter::MARKER_SHARED),
        );
        $this->assertSame(
            'Rushi Patel',
            \App\Console\Commands\ImportSahajLegacy::displayName('RUSHI PATEL'),
        );
    }

    /** Every patient without their own number must carry [Action needed]. */
    public function test_no_patient_missing_a_number_is_left_unflagged(): void
    {
        $shared = '9824459668';
        $importer = (new SahajLegacyImporter([
            // Parent and child on one number; both have eye tests. Only one can
            // hold the number, so the other must still be flagged.
            ['id' => '1', 'name' => 'MOTHER PATEL', 'contectno' => $shared, 'date' => '2022-01-01', 'lspl' => '-1.00'],
            ['id' => '2', 'name' => 'CHILD PATEL', 'contectno' => $shared, 'date' => '2025-01-01', 'lspl' => '-2.00'],
        ], []))->analyse();

        foreach ($importer->profiles as $p) {
            if ($p['is_patient'] && $p['phone'] === null) {
                $this->assertSame(SahajLegacyImporter::MARKER_ACTION, $p['marker'],
                    "patient '{$p['name']}' has no number and must be flagged for action");
            }
        }

        // The newest visit keeps the number; the parent loses it but, being a
        // patient, is flagged for action rather than merely noted as shared.
        $byName = array_column($importer->profiles, null, 'name');
        $this->assertSame('+91 ' . $shared, $byName['CHILD PATEL']['phone']);
        $this->assertNull($byName['CHILD PATEL']['marker']);
        $this->assertNull($byName['MOTHER PATEL']['phone']);
        $this->assertSame(SahajLegacyImporter::MARKER_ACTION, $byName['MOTHER PATEL']['marker']);
    }

    // -------------------------------------------------------------- checkedby

    public function test_old_variants_become_self_and_clinics_are_kept(): void
    {
        foreach (['OLD', 'Old', '0LD', 'OLDD', 'OLD RECO.', 'OLD/RUSHI'] as $legacy) {
            $this->assertSame('Self', SahajLegacyImporter::mapCheckedBy($legacy),
                "'{$legacy}' means the customer brought their own prescription");
        }

        $this->assertSame('RUSHI', SahajLegacyImporter::mapCheckedBy('RUSHI'));
        $this->assertSame('KALARIYA HOS.', SahajLegacyImporter::mapCheckedBy('KALARIYA HOS.'));
        $this->assertNull(SahajLegacyImporter::mapCheckedBy(''));
    }

    // -------------------------------------------------- low-priority dropping

    /**
     * Confirmed with the shop owner: a profile earns its place by being either
     * contactable (a number on record) or clinically useful (an eye test).
     * Neither means there is nothing to keep.
     */
    public function test_a_profile_with_no_phone_and_no_eye_test_is_dropped(): void
    {
        $importer = (new SahajLegacyImporter([], [
            ['first_name' => 'GHOST WALKIN', 'contact' => '0000000000', 'date' => '2024-03-04', 'total' => '900', 'order_no' => '1'],
        ]))->analyse();

        $this->assertCount(0, $importer->profiles);
        $this->assertCount(1, $importer->lowPriority);
        $this->assertSame('GHOST WALKIN', $importer->lowPriority[0]['name']);
    }

    public function test_an_eye_test_alone_is_enough_to_keep_a_profile(): void
    {
        $importer = (new SahajLegacyImporter([
            ['id' => '1', 'name' => 'NO PHONE PATIENT', 'contectno' => '0000000000', 'date' => '2024-03-04', 'lspl' => '-1.00'],
        ], []))->analyse();

        $this->assertCount(1, $importer->profiles);
        $this->assertSame(1, $importer->profiles[0]['eye_record_count']);
        // ...and it is flagged, because the shop still needs a number for them.
        $this->assertTrue($importer->profiles[0]['needs_action']);
    }

    public function test_a_phone_alone_is_enough_to_keep_a_profile(): void
    {
        $importer = (new SahajLegacyImporter([], [
            ['first_name' => 'BUYER ONLY', 'contact' => '9824459668', 'date' => '2024-03-04', 'total' => '900', 'order_no' => '1'],
        ]))->analyse();

        $this->assertCount(1, $importer->profiles);
        $this->assertCount(0, $importer->lowPriority);
        $this->assertFalse($importer->profiles[0]['needs_action']);
    }

    /**
     * The destructive misreading of the rule: someone who DID have a number but
     * lost a shared household one to a more recent visitor still has a number on
     * record, and must survive.
     */
    public function test_losing_a_shared_number_does_not_make_a_person_droppable(): void
    {
        $shared = '9824459668';
        $importer = (new SahajLegacyImporter([], [
            // Two different people on one family number, neither with an eye test.
            ['first_name' => 'MOTHER PATEL', 'contact' => $shared, 'date' => '2022-01-01', 'total' => '500', 'order_no' => '1'],
            ['first_name' => 'CHILD PATEL', 'contact' => $shared, 'date' => '2025-01-01', 'total' => '500', 'order_no' => '2'],
        ]))->analyse();

        $this->assertCount(2, $importer->profiles, 'neither person may be dropped');
        $this->assertCount(0, $importer->lowPriority);

        $names = array_column($importer->profiles, 'name');
        $this->assertContains('MOTHER PATEL', $names);
        $this->assertContains('CHILD PATEL', $names);

        // The newest visit keeps the number; the other is flagged, not deleted.
        $byName = array_column($importer->profiles, null, 'name');
        $this->assertSame('+91 ' . $shared, $byName['CHILD PATEL']['phone']);
        $this->assertNull($byName['MOTHER PATEL']['phone']);
        $this->assertTrue($byName['MOTHER PATEL']['had_phone_on_record']);
        // Neither has an eye test, so this is context, not a to-do.
        $this->assertSame(SahajLegacyImporter::MARKER_SHARED, $byName['MOTHER PATEL']['marker']);
    }

    public function test_dropping_is_judged_on_the_merged_person_not_the_single_row(): void
    {
        // The estimate row alone has neither a phone nor an eye test, but it is
        // the same person as the eye-record row and must not be dropped.
        $importer = (new SahajLegacyImporter([
            ['id' => '1', 'name' => 'ANJALI SHAH', 'contectno' => '0000000000', 'date' => '2024-05-01', 'lspl' => '-2.00'],
        ], [
            ['first_name' => 'ANJALI SHAH', 'contact' => '0000000000', 'date' => '2024-06-01', 'total' => '900', 'order_no' => '1'],
        ]))->analyse();

        $this->assertCount(1, $importer->profiles);
        $this->assertCount(0, $importer->lowPriority);
        $this->assertSame(2, $importer->profiles[0]['source_rows']);
    }

    // ------------------------------------------------------ identity grouping

    /** @param list<array<string,string>> $rows */
    private function eye(array $rows): array
    {
        return array_map(fn ($r) => array_merge([
            'id' => '1', 'name' => '', 'contectno' => '', 'checkedby' => '',
            'lspl' => '', 'lcly' => '', 'laxis' => '', 'lvs' => '', 'leftspl' => '',
            'rspl' => '', 'rcly' => '', 'raxis' => '', 'rvs' => '', 'rightspl' => '',
            'leftadd' => '', 'rightadd' => '', 'date' => '2024-01-01',
        ], $r), $rows);
    }

    public function test_middle_name_variants_on_one_phone_merge_into_one_person(): void
    {
        $importer = (new SahajLegacyImporter($this->eye([
            ['id' => '1', 'name' => 'AMIT TRILOCHAN SHARMA', 'contectno' => '9825622900', 'date' => '2024-01-01'],
            ['id' => '2', 'name' => 'AMIT SHARMA', 'contectno' => '9825622900', 'date' => '2025-06-01'],
        ]), []))->analyse();

        $this->assertCount(1, $importer->profiles, 'the same person, spelled two ways');
        $this->assertSame(2, $importer->profiles[0]['eye_record_count']);
        $this->assertSame('+91 9825622900', $importer->profiles[0]['phone']);
    }

    public function test_different_people_on_a_shared_family_phone_are_never_merged(): void
    {
        $importer = (new SahajLegacyImporter($this->eye([
            ['id' => '1', 'name' => 'DHARAM JAYESH KHAPARA', 'contectno' => '9227790011', 'date' => '2024-01-01'],
            ['id' => '2', 'name' => 'KAVYA KHAPRA', 'contectno' => '9227790011', 'date' => '2025-01-01'],
        ]), []))->analyse();

        // Two people, two profiles — merging would misfile a prescription.
        $this->assertCount(2, $importer->profiles);

        // Only one row may hold a number (customers are unique per tenant+phone),
        // so the most recent visitor keeps it and the other gets a placeholder.
        $phones = array_column($importer->profiles, 'phone');
        $this->assertContains('+91 9227790011', $phones);
        $this->assertContains(null, $phones);

        // Both are surfaced for review rather than silently resolved.
        $this->assertCount(2, $importer->manualReview);
        $this->assertSame('KAVYA KHAPRA', $importer->manualReview[1]['name']);
        $this->assertStringContainsString('KEEPS', $importer->manualReview[1]['outcome']);
    }

    public function test_one_person_across_two_numbers_keeps_the_most_recent(): void
    {
        $importer = (new SahajLegacyImporter($this->eye([
            ['id' => '1', 'name' => 'MAHI SONI', 'contectno' => '9979991379', 'date' => '2023-05-01'],
            ['id' => '2', 'name' => 'MAHI SONI', 'contectno' => '9979281277', 'date' => '2025-12-06'],
        ]), []))->analyse();

        $this->assertCount(1, $importer->profiles);
        $this->assertSame('+91 9979281277', $importer->profiles[0]['phone'], 'newest number wins');
        $this->assertCount(1, $importer->autoPickedPhone);
        $this->assertStringContainsString('9979991379', $importer->autoPickedPhone[0]['other_phones_on_record']);
    }

    public function test_a_person_is_not_split_across_the_two_legacy_tables(): void
    {
        // Same human: eye-tested (phone on file) and later bought glasses (no phone).
        $importer = (new SahajLegacyImporter(
            $this->eye([['id' => '1', 'name' => 'RAHUL PATEL', 'contectno' => '9876543210']]),
            [['order_no' => '1', 'first_name' => 'RAHUL PATEL', 'contact' => '', 'total' => '1500', 'date' => '2024-06-01']],
        ))->analyse();

        $this->assertCount(1, $importer->profiles, 'one person, not one per table');
        $this->assertSame('+91 9876543210', $importer->profiles[0]['phone']);
        $this->assertSame(2, $importer->profiles[0]['source_rows']);
    }

    public function test_identical_resubmitted_eye_records_are_deduplicated(): void
    {
        $importer = (new SahajLegacyImporter($this->eye([
            ['id' => '1', 'name' => 'MIHIR SHAH', 'contectno' => '9664948286', 'lspl' => '-0.50', 'date' => '2024-03-03'],
            ['id' => '2', 'name' => 'MIHIR SHAH', 'contectno' => '9664948286', 'lspl' => '-0.50', 'date' => '2024-03-03'],
        ]), []))->analyse();

        $this->assertSame(1, $importer->profiles[0]['eye_record_count']);
        $this->assertCount(1, $importer->excluded);
    }

    // --------------------------------------------------------- field mapping

    public function test_prescription_maps_to_the_right_eye_and_near_vision_field(): void
    {
        // Real row: sph + add = nv held for all 1,316 checkable rows in the dataset.
        $importer = (new SahajLegacyImporter($this->eye([[
            'id' => '7', 'name' => 'ILA KAILASH ANTALA', 'contectno' => '9724600711',
            'checkedby' => 'KALARIYA HOS.',
            'lspl' => '-0.75', 'lcly' => '+3.25', 'laxis' => '175', 'lvs' => '6/9',
            'leftspl' => '+1.50', 'leftadd' => '+2.25',
            'rspl' => '-0.50', 'rcly' => '+2.27', 'raxis' => '180', 'rvs' => '6/9',
            'rightspl' => '+1.75', 'rightadd' => '+2.25',
            'date' => '2023-11-27',
        ]]), []))->analyse();

        $r = $importer->profiles[0]['eye_records'][0];

        // Legacy "l" is the left eye (OS); "r" is the right (OD).
        $this->assertSame(-0.75, $r['os_sph']);
        $this->assertSame(-0.50, $r['od_sph']);
        $this->assertSame(175, $r['os_axis']);
        $this->assertSame('6/9', $r['os_va']);

        // leftspl/rightspl are NEAR-VISION powers, and satisfy sph + add = nv.
        $this->assertSame(1.50, $r['os_nv']);
        $this->assertSame(1.75, $r['od_nv']);
        $this->assertEqualsWithDelta($r['os_sph'] + $r['os_add'], $r['os_nv'], 0.001);
        $this->assertEqualsWithDelta($r['od_sph'] + $r['od_add'], $r['od_nv'], 0.001);

        $this->assertSame('KALARIYA HOS.', $r['checked_by']);
    }

    // -------------------------------------------------- estimatebook filtering

    public function test_non_positive_totals_are_treated_as_bookkeeping_not_customers(): void
    {
        $importer = (new SahajLegacyImporter([], [
            ['order_no' => '1', 'first_name' => 'BHUL', 'contact' => '', 'total' => '-700', 'date' => '2023-12-11'],
            ['order_no' => '2', 'first_name' => 'REAL PERSON', 'contact' => '9876543210', 'total' => '0', 'date' => '2023-12-20'],
            ['order_no' => '3', 'first_name' => 'GENUINE BUYER', 'contact' => '9876500001', 'total' => '1500', 'date' => '2024-01-01'],
        ]))->analyse();

        $this->assertCount(1, $importer->profiles);
        $this->assertSame('GENUINE BUYER', $importer->profiles[0]['name']);
        $this->assertCount(2, $importer->excluded);
    }

    // ------------------------------------------------------------- SQL parser

    public function test_sql_parser_handles_quotes_nulls_and_escapes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sql');
        file_put_contents($path, <<<'SQL'
        INSERT INTO `eyerecourd` (`id`, `name`, `contectno`, `lvs`, `date`) VALUES
        (1, 'MIHIR SHAH', 9664948286, '6/4', '2023-11-26'),
        (2, 'O\'BRIEN, PATRICK', 9426526687, '6/6', '2023-11-27'),
        (3, NULL, 0, '', '2023-11-28');
        SQL);

        $rows = SqlDumpParser::parseTable($path, 'eyerecourd');
        @unlink($path);

        $this->assertCount(3, $rows);
        // A comma and an apostrophe inside a value must not split the row.
        $this->assertSame("O'BRIEN, PATRICK", $rows[1]['name']);
        // A Snellen fraction stays a fraction — the reason SQL beat CSV here.
        $this->assertSame('6/4', $rows[0]['lvs']);
        // Bare NULL is absence; '' is an empty string.
        $this->assertNull($rows[2]['name']);
        $this->assertSame('', $rows[2]['lvs']);
    }
}
