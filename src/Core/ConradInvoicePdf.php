<?php declare(strict_types=1);

namespace TaxApiConrad\Core;

use Smalot\PdfParser\Parser;
use Taxmod\Core\Import\Mail\Text;

/**
 * What a Conrad invoice PDF says: the order it bills, its date, the amount due and whether it is paid (D-1024).
 * The mail itself names only the invoice and customer number; the order number is in the PDF alone.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/import/konzept.md
 */
final class ConradInvoicePdf
{
    /** @param string|null $date Y-m-d; @param string|null $total decimal string; @param bool|null $paid null: the PDF does not say */
    private function __construct(
        public readonly string $orderNumber,
        public readonly ?string $date,
        public readonly ?string $total,
        public readonly ?bool $paid,
        public readonly ?string $paymentMethod,
    ) {
    }

    /** Null when the bytes are no readable PDF or name no order. */
    public static function read(string $pdf, string $invoiceNumber): ?self
    {
        try {
            $text = (new Parser())->parseContent($pdf)->getText();
        } catch (\Throwable) {
            // A broken attachment leaves the invoice as the mail alone describes it.
            return null;
        }

        return self::fromText($text, $invoiceNumber);
    }

    public static function fromText(string $text, string $invoiceNumber): ?self
    {
        // The order number stands unlabelled under the items; some extractors glue it to the invoice number in the barcode text.
        $glued = $invoiceNumber !== '' && preg_match('/' . preg_quote($invoiceNumber, '/') . '(\d{10})(?!\d)/', $text, $found) === 1;

        if (! $glued && preg_match('/^\s*(\d{10})\s*$/m', $text, $found) !== 1) {
            return null;
        }

        if ($found[1] === $invoiceNumber) {
            return null;
        }

        $date = preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $text, $day) === 1 ? $day[3] . '-' . $day[2] . '-' . $day[1] : null;
        $total = preg_match('/Endbetrag\s*([\d.]*\d,\d{2})/u', $text, $sum) === 1 || preg_match('/Summe Bruttowert\s*([\d.]*\d,\d{2})/u', $text, $sum) === 1
            ? Text::decimal((float) str_replace(['.', ','], ['', '.'], $sum[1])) : null;
        $method = preg_match('/Bezahlt mit\s+(\S[^\n\t]*?)\s*$/mu', $text, $paidWith) === 1 ? $paidWith[1] : null;

        return new self($found[1], $date, $total, $method !== null ? true : null, $method);
    }
}
