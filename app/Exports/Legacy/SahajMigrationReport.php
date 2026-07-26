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
            $this->needsPhone(),
            $this->sharedPhone(),
            $this->mergedNames(),
            $this->autoPickedPhone(),
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
            ['Rows excluded (see "Excluded Rows" tab)', $s['excluded_rows']],
            ['', ''],
            ['WHAT GETS CREATED', ''],
            ['Customer profiles', $s['profiles_to_import']],
            ['  ...with a real phone number', $s['with_real_phone']],
            ['  ...with a placeholder (no phone on record)', $s['with_placeholder_phone']],
            ['Eye prescriptions', $s['eye_records_to_import']],
            ['', ''],
            ['THINGS TO LOOK AT', ''],
            ['Profiles sharing a phone (see "Action - Shared Phone")', $s['flagged_for_review']],
            ['Profiles merged from name variants (see "Merged Names")', $s['merged_name_variants']],
            ['Phone auto-chosen from newest visit (see "Phone Auto-Picked")', $s['phone_auto_picked']],
            ['', ''],
            ['HOW TO USE THIS WORKBOOK', ''],
            ['1.', 'Skim "Merged Names" — confirm no two different people were combined.'],
            ['2.', '"Action - Shared Phone" is the only tab needing decisions.'],
            ['3.', '"Action - Needs Phone" is a front-desk checklist for collecting numbers.'],
            ['4.', '"Excluded Rows" proves nothing was dropped silently.'],
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
