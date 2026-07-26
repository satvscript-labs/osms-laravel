<?php

namespace App\Exports\Legacy;

use App\Support\Legacy\SahajLegacyImporter;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The migration workbook handed to the shop owner.
 *
 * Split into task-shaped tabs rather than one giant dump: "Summary" explains the
 * run, "Action - Needs Phone" is a front-desk checklist, "Action - Shared Phone"
 * is the only tab needing a real decision, and "Excluded Rows" exists so nothing
 * is silently dropped — every discarded row is listed with the reason.
 */
class SahajMigrationReport implements WithMultipleSheets
{
    public function __construct(
        private SahajLegacyImporter $importer,
        private bool $committed,
    ) {}

    public function sheets(): array
    {
        return [
            $this->summary(),
            $this->profiles(),
            $this->actionNeeded(),
            $this->sharedNumber(),
            $this->needsPhone(),
            $this->sharedPhone(),
            $this->mergedNames(),
            $this->autoPickedPhone(),
            $this->lowPriority(),
            $this->excluded(),
        ];
    }

    private function summary(): ReportSheet
    {
        $s = $this->importer->stats;

        $rows = [
            ['Run mode', $this->committed ? 'COMMITTED — data was written' : 'DRY RUN — nothing was written'],
            ['Generated', now()->format('d M Y, H:i')],
            ['', ''],
            ['SOURCE DATA', ''],
            ['Legacy eye-record rows read', $s['source_eye_rows']],
            ['Legacy estimate-book rows read', $s['source_estimate_rows']],
            ['Rows excluded — not customers (see "Excluded Rows")', $s['excluded_rows']],
            ['Profiles skipped — no phone AND no eye test (see "Skipped - Nothing To Keep")', $s['low_priority_dropped']],
            ['', ''],
            ['WHAT GETS CREATED', ''],
            ['Customer profiles', $s['profiles_to_import']],
            ['  ...with a phone number of their own', $s['with_real_phone']],
            ['  ...with a placeholder (no number of their own)', $s['with_placeholder_phone']],
            ['  ...that are PATIENTS (have at least one eye test)', $s['patients']],
            ['Eye prescriptions', $s['eye_records_to_import']],
            ['', ''],
            ['NAME MARKERS', ''],
            ['Marked "[Action needed]" (see "Action Needed")', $s['needs_action']],
            ['  ...patients missing a number of their own', $s['patients_needing_a_number']],
            ['  ...odd name, but a real number is attached', $s['kept_despite_odd_name']],
            ['Marked "[Shared number]" (see "Shared Number")', $s['shared_number']],
            ['  ...non-patients reachable on a relative\'s number', $s['shared_number']],
            ['', ''],
            ['THINGS TO LOOK AT', ''],
            ['Profiles sharing a phone (see "Action - Shared Phone")', $s['flagged_for_review']],
            ['Profiles merged from name variants (see "Merged Names")', $s['merged_name_variants']],
            ['Phone auto-chosen from newest visit (see "Phone Auto-Picked")', $s['phone_auto_picked']],
            ['', ''],
            ['HOW TO USE THIS WORKBOOK', ''],
            ['1.', '"Action Needed" is the priority list — patients first. Fill in the last column.'],
            ['2.', 'Skim "Merged Names" — confirm no two different people were combined.'],
            ['3.', '"Shared Number" is FYI only: these people are reachable via a relative.'],
            ['4.', '"Action - Shared Phone" is the only tab needing decisions.'],
            ['5.', '"Skipped - Nothing To Keep" lists names with no number and no eye test.'],
            ['6.', '"Excluded Rows" proves nothing was dropped silently.'],
        ];

        return new ReportSheet('Summary', ['Item', 'Value'], $rows);
    }

    private function profiles(): ReportSheet
    {
        $rows = [];
        foreach ($this->importer->profiles as $p) {
            $rows[] = [
                $p['name'],
                $p['phone'] ?? '(placeholder — no phone on record)',
                $p['eye_record_count'],
                $p['first_seen'],
                $p['last_seen'],
                $p['source_rows'],
                $p['sources'],
                count($p['name_variants']) > 1 ? implode(' / ', $p['name_variants']) : '',
            ];
        }

        return new ReportSheet(
            'All Profiles',
            ['Name', 'Phone', 'Eye records', 'First seen', 'Last seen', 'Source rows', 'From tables', 'Merged name variants'],
            $rows,
        );
    }

    /**
     * Every profile carrying the "[Action needed]" suffix in the app, with the
     * reason — so the shop can work this tab top-to-bottom and clear the marker.
     *
     * Patients come first: a file with eye tests in it is the one the shop most
     * needs to keep hold of, so it should be the first thing worked through.
     */
    private function actionNeeded(): ReportSheet
    {
        $flagged = array_filter($this->importer->profiles, fn ($p) => $p['needs_action']);
        usort($flagged, fn ($a, $b) => [$b['is_patient'], $b['eye_record_count']] <=> [$a['is_patient'], $a['eye_record_count']]);

        $rows = [];
        foreach ($flagged as $p) {
            $rows[] = [
                $p['name'],
                $p['is_patient'] ? 'PATIENT' : '',
                $p['phone'] ?? '(placeholder — no number of their own)',
                $p['marker_reason'],
                $p['phone'] === null ? 'Add the real number, or delete the profile' : 'Correct the name, or delete the profile',
                $p['eye_record_count'],
                $p['shares_phone_with'],
                $p['last_seen'],
                '', // left blank for staff to write the real number in
            ];
        }

        return new ReportSheet(
            'Action Needed',
            ['Name', 'Patient?', 'Phone', 'Why it is flagged', 'What to do', 'Eye records',
                'Relative holding the number', 'Last visit', 'REAL PHONE (fill in)'],
            $rows,
        );
    }

    /**
     * Non-patients reachable on a relative's number. Not a to-do list — it
     * exists so the placeholder number on those profiles has an explanation.
     */
    private function sharedNumber(): ReportSheet
    {
        $rows = [];
        foreach ($this->importer->profiles as $p) {
            if ($p['marker'] !== SahajLegacyImporter::MARKER_SHARED) {
                continue;
            }
            $rows[] = [
                $p['name'],
                $p['shares_phone_with'],
                $p['source_rows'],
                $p['first_seen'],
                $p['last_seen'],
                $p['sources'],
            ];
        }

        return new ReportSheet(
            'Shared Number',
            ['Name', 'Number is held by', 'Times seen', 'First seen', 'Last seen', 'From tables'],
            $rows,
        );
    }

    private function needsPhone(): ReportSheet
    {
        $rows = [];
        foreach ($this->importer->profiles as $p) {
            if ($p['phone'] !== null) {
                continue;
            }
            $rows[] = [
                $p['name'],
                $p['eye_record_count'],
                $p['last_seen'],
                $p['shares_phone_with'] !== ''
                    ? 'Shared a number with: ' . $p['shares_phone_with']
                    : 'No phone in the old system',
                '', // left blank for staff to write the real number in
            ];
        }

        return new ReportSheet(
            'Action - Needs Phone',
            ['Name', 'Eye records', 'Last visit', 'Why no phone', 'REAL PHONE (fill in)'],
            $rows,
        );
    }

    private function sharedPhone(): ReportSheet
    {
        $rows = [];
        foreach ($this->importer->manualReview as $p) {
            $rows[] = [
                $p['name'],
                $p['contested_phone'],
                $p['outcome'],
                $p['shares_phone_with'],
                $p['eye_record_count'],
                $p['last_seen'],
            ];
        }

        return new ReportSheet(
            'Action - Shared Phone',
            ['Name', 'Shared number', 'What happened', 'Shares it with', 'Eye records', 'Last visit'],
            $rows,
        );
    }

    private function mergedNames(): ReportSheet
    {
        $rows = [];
        foreach ($this->importer->profiles as $p) {
            if (count($p['name_variants']) < 2) {
                continue;
            }
            $rows[] = [
                $p['name'],
                $p['phone'] ?? '(placeholder)',
                implode(' / ', $p['name_variants']),
                count($p['name_variants']),
                $p['eye_record_count'],
            ];
        }

        return new ReportSheet(
            'Merged Names',
            ['Kept as', 'Phone', 'All spellings found', 'Variants', 'Eye records'],
            $rows,
        );
    }

    private function autoPickedPhone(): ReportSheet
    {
        $rows = [];
        foreach ($this->importer->autoPickedPhone as $a) {
            $rows[] = [
                $a['name'],
                $a['chosen_phone'],
                $a['chosen_from_date'],
                $a['other_phones_on_record'],
                $a['note'],
            ];
        }

        return new ReportSheet(
            'Phone Auto-Picked',
            ['Name', 'Number kept', 'From visit dated', 'Older numbers found', 'Note'],
            $rows,
        );
    }

    /**
     * Names that survived every junk filter but hold nothing usable — no number
     * and no eye test. Listed in full so the decision to skip them is auditable
     * and reversible.
     */
    private function lowPriority(): ReportSheet
    {
        $rows = [];
        foreach ($this->importer->lowPriority as $p) {
            $rows[] = [
                $p['name'],
                $p['name_variants'],
                $p['source_rows'],
                $p['sources'],
                $p['first_seen'],
                $p['last_seen'],
                $p['reason'],
            ];
        }

        return new ReportSheet(
            'Skipped - Nothing To Keep',
            ['Name as stored', 'All spellings found', 'Times seen', 'From tables', 'First seen', 'Last seen', 'Why skipped'],
            $rows,
        );
    }

    private function excluded(): ReportSheet
    {
        $rows = [];
        foreach ($this->importer->excluded as $e) {
            $rows[] = [
                $e['source'],
                $e['source_id'],
                $e['name'],
                $e['phone'],
                $e['date'],
                $e['reason'],
            ];
        }

        return new ReportSheet(
            'Excluded Rows',
            ['Legacy table', 'Legacy ID', 'Name as stored', 'Phone as stored', 'Date', 'Why excluded'],
            $rows,
        );
    }
}
