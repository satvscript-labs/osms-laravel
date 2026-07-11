<?php

namespace App\Support;

/**
 * FT-TaxInvoice — splits a tax-inclusive amount into its taxable value + GST,
 * assuming a single intra-state sale (CGST + SGST split evenly, never IGST —
 * every OSMS store is one physical location). Item prices are entered
 * tax-inclusive (the retail norm here), so the tax is backed out rather than
 * added on top.
 */
class Gst
{
    /**
     * @return array{taxable: float, cgst: float, sgst: float, tax: float}
     */
    public static function splitInclusive(float $inclusiveAmount, float $ratePercent): array
    {
        if ($ratePercent <= 0) {
            return ['taxable' => round($inclusiveAmount, 2), 'cgst' => 0.0, 'sgst' => 0.0, 'tax' => 0.0];
        }

        $taxable = round($inclusiveAmount / (1 + $ratePercent / 100), 2);
        $tax = round($inclusiveAmount - $taxable, 2);
        $half = round($tax / 2, 2);

        return ['taxable' => $taxable, 'cgst' => $half, 'sgst' => $tax - $half, 'tax' => $tax];
    }
}
