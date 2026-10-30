<?php declare(strict_types=1);

namespace TaxApiConrad\Core;

use Taxmod\Core\Import\Mail\MailMessage;
use Taxmod\Core\Import\Mail\LineCollector;
use Taxmod\Core\Import\Mail\OrderMailParser;
use Taxmod\Core\Import\Mail\Text;
use Taxmod\Core\Import\InvoiceFacts;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;

/**
 * Conrad: «Bestellt» mail (items, shipping, sum), shipping and delivery mails, invoices.
 * Moved here from Taxmod Mail (D-1006). Port of legacy/kaeufe-import/kaeufe_import/shops/conrad.py. An invoice mail names only its own number;
 * the order, date, amount and payment come from the attached PDF, which is kept in the encrypted document store (D-1023, D-1024).
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/import/module-schnitt.md
 */
final class ConradMailParser implements OrderMailParser
{
    /** @param (\Closure(string, string, string): string)|null $keepDocument stores a PDF and returns its `vault:<key>` */
    public function __construct(private readonly string $version, private readonly ?\Closure $keepDocument = null)
    {
    }

    private const MERCHANT = 'Conrad Electronic';

    public function key(): string
    {
        return 'conrad';
    }

    public function shopName(): string
    {
        return 'Conrad';
    }

    public function recognises(MailMessage $mail): bool
    {
        return $mail->senderDomain() === 'conrad.de';
    }

    public function parse(MailMessage $mail): OrderProposal
    {
        $lines = $mail->lines();
        $subject = $mail->subject;
        $text = implode("\n", $lines);

        if (str_contains($subject, 'Rechnung')) {
            return $this->invoice($mail);
        }

        if (preg_match('/(\d{10})\s*$/', $subject, $match) !== 1 && preg_match('/Auftrag (\d{10})/', $subject, $match) !== 1
            && preg_match('/Bestellnummer:\n(\d{10})/', $text, $match) !== 1 && preg_match('/Bestellung\n?\s*(\d{10})/', $text, $match) !== 1) {
            return new OrderProposal($this->shopName(), '', ProposalKind::NoReceipt);
        }

        if (! str_starts_with($subject, 'Bestellt')) {
            // «wird voraussichtlich heute zugestellt» is on its way, not delivered (parcelLab, 2026-10-28).
            $head = mb_substr($text, 0, 300);
            $delivered = str_contains($subject, 'geliefert') || (str_contains($head, 'zugestellt') && ! str_contains($head, 'voraussichtlich'));

            return new OrderProposal($this->shopName(), $match[1], $delivered ? ProposalKind::Delivered : ProposalKind::Shipped,
                counterparty: self::MERCHANT, shippedDate: $delivered ? null : $mail->date?->format('Y-m-d'));
        }

        $items = new LineCollector();

        foreach ($lines as $k => $line) {
            if (str_starts_with($line, 'Menge:') && $k > 0 && $k + 2 < count($lines) && preg_match('/\d+/', $line, $quantity) === 1) {
                $unit = str_starts_with($lines[$k + 1], 'Einzelpreis') ? Text::money($lines[$k + 1])[0] : null;
                $article = str_starts_with($lines[$k + 2], 'Artikelnummer') && preg_match('/\d+/', $lines[$k + 2], $number) === 1 ? $number[0] : null;
                // The 2026 layout puts «Lieferung: …» between the title and the quantity.
                $title = $k > 1 && str_starts_with($lines[$k - 1], 'Lieferung:') ? $lines[$k - 2] : $lines[$k - 1];
                // «Einzelpreis» is rounded to the cent (100 resistors at «€ 0,02» cost € 1,62); the line total is exact.
                $lineTotal = self::lineTotal($lines, $k);
                $unit = $lineTotal !== null && (float) $quantity[0] > 0 ? $lineTotal / (float) $quantity[0] : $unit;
                $items->add($title, (float) $quantity[0], $unit, $article);
            }
        }

        return new OrderProposal($this->shopName(), $match[1], ProposalKind::Ordered, $mail->date?->format('Y-m-d'), 'EUR', $items->lines(),
            Text::decimal(Text::money(Text::after($lines, 'Versandkosten'))[0]), Text::decimal(Text::money(Text::after($lines, 'Summe'))[0]), self::MERCHANT);
    }

    private function invoice(MailMessage $mail): OrderProposal
    {
        $pdf = $mail->pdfs()[0] ?? null;
        $number = preg_match('/\((\d{10}),/', $mail->subject, $found) === 1 || preg_match('/^(\d{10})\.pdf$/i', $pdf->fileName ?? '', $found) === 1 ? $found[1] : '';
        $facts = $pdf !== null ? ConradInvoicePdf::read($pdf->content, $number) : null;

        // Before modeller 0.1.95 there is no invoice record; the mail is then shown for discarding, as before.
        if ($facts === null || ! class_exists(InvoiceFacts::class)) {
            return new OrderProposal($this->shopName(), $number, ProposalKind::Invoice, counterparty: self::MERCHANT);
        }

        $document = $this->keepDocument !== null ? ($this->keepDocument)($pdf->content, $pdf->fileName, 'application/pdf') : null;

        return new OrderProposal($this->shopName(), $facts->orderNumber, ProposalKind::Invoice, total: $facts->total, counterparty: self::MERCHANT,
            invoice: new InvoiceFacts($number, $facts->date, $facts->paid, $facts->paymentMethod, $document));
    }

    /** The «€ 1,62» line that closes an item, within the few lines after its quantity; null when the layout has none. */
    private static function lineTotal(array $lines, int $quantityAt): ?float
    {
        for ($k = $quantityAt + 1; $k <= $quantityAt + 5 && isset($lines[$k]); $k++) {
            if (str_starts_with($lines[$k], 'Menge:')) {
                return null;
            }

            if (preg_match('/^€\s*[\d.,]+$/u', $lines[$k]) === 1) {
                return Text::money($lines[$k])[0];
            }
        }

        return null;
    }

    public function kinds(): array
    {
        return [ProposalKind::Ordered, ProposalKind::Shipped, ProposalKind::Delivered, ProposalKind::Invoice];
    }

    public function version(): string
    {
        return $this->version;
    }
}
