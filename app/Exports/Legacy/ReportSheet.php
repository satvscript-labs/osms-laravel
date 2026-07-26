<?php

namespace App\Exports\Legacy;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One tab of the migration workbook: a bold, frozen header over plain rows.
 *
 * Generic on purpose — every sheet in the report is "some headings and some
 * rows", so the shape lives here once instead of in seven near-identical classes.
 */
class ReportSheet implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<scalar|null>>  $rows
     */
    public function __construct(
        private string $title,
        private array $headings,
        private array $rows,
    ) {}

    public function title(): string
    {
        // Excel caps sheet names at 31 characters and rejects : \ / ? * [ ].
        return mb_substr(preg_replace('/[:\\\\\/?*\[\]]/', '-', $this->title) ?? '', 0, 31);
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function styles(Worksheet $sheet): array
    {
        // Freeze the header so it stays visible while scrolling thousands of rows.
        $sheet->freezePane('A2');

        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '004F75'], // OSMS deep optical blue
                ],
            ],
        ];
    }
}
