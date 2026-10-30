<?php declare(strict_types=1);

namespace TaxApiConrad\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxApiConrad\Core\ConradInvoicePdf;
use TaxApiConrad\Core\ConradMailParser;
use Taxmod\Core\Import\Mail\MimeParser;

/**
 * Each expectation is what the Python importer (legacy/ in Taxmod Mail) read from the same synthetic mail.
 */
final class ConradMailParserTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function read(string $fixture, ?\Closure $keepDocument = null): array
    {
        $parser = new ConradMailParser('0.0.1', $keepDocument);
        $mail = (new MimeParser())->parse(1, (string) file_get_contents(__DIR__ . '/fixtures/' . $fixture . '.eml'));
        self::assertTrue($parser->recognises($mail), 'not recognised: ' . $fixture);

        return $parser->parse($mail)->toArray();
    }

    public function testConradOrdered(): void
    {
        $order = self::read('c1');

        self::assertSame(['1234567890', '4.95', '20.93'], [$order['order_number'], $order['shipping_cost'], $order['total']]);
        self::assertSame([['title' => 'Lötzinn 100g', 'quantity' => 2.0, 'unit_price' => '7.99', 'article_number' => '123456']], $order['lines']);
    }

    public function testConrad2026LayoutTakesTitleAboveDeliveryAndExactUnitPrice(): void
    {
        $order = self::read('c2');

        self::assertSame(['2020147197', '4.95', '7.57'], [$order['order_number'], $order['shipping_cost'], $order['total']]);
        self::assertSame([
            ['title' => 'Yageo MF0207F220RH Metallschicht-Widerstand 220 Ω 1 St.', 'quantity' => 100.0, 'unit_price' => '0.0162', 'article_number' => '1417626'],
            ['title' => 'Kontakt Chemie 814727 Sprühröhrchen 5 St.', 'quantity' => 10.0, 'unit_price' => '0.1', 'article_number' => '814727'],
        ], $order['lines']);
    }

    public function testConradOnItsWayIsShippedNotDelivered(): void
    {
        $order = self::read('c3');

        self::assertSame(['2020147197', 'shipped'], [$order['order_number'], $order['kind']]);
    }

    public function testItNamesWhatItReads(): void
    {
        self::assertNotSame([], (new ConradMailParser('1.2.3'))->kinds());
        self::assertSame('1.2.3', (new ConradMailParser('1.2.3'))->version());
    }

    public function testConradInvoiceReadsOrderAmountAndPaymentFromItsPdf(): void
    {
        $kept = [];
        $invoice = self::read('c4', static function (string $content, string $fileName, string $mimeType) use (&$kept): string {
            $kept[] = [str_starts_with($content, '%PDF'), $fileName, $mimeType];

            return 'vault:' . str_repeat('a', 32);
        });

        self::assertSame(['invoice', '2020000001', '6.57'], [$invoice['kind'], $invoice['order_number'], $invoice['total']]);
        self::assertSame(['number' => '1111111111', 'date' => '2026-10-26', 'paid' => true, 'payment_method' => 'PayPal',
            'document' => 'vault:' . str_repeat('a', 32)], $invoice['invoice']);
        self::assertSame([[true, '1111111111.pdf', 'application/pdf']], $kept);
    }

    public function testConradInvoiceFindsTheOrderGluedToTheInvoiceNumber(): void
    {
        $facts = ConradInvoicePdf::fromText("Rechnung\n26.10.2026\n825169256024730A411111111112020000001\nEUREndbetrag        20,09\n", '1111111111');

        self::assertSame(['2020000001', '20.09', null], [$facts?->orderNumber, $facts?->total, $facts?->paid]);
    }

    public function testConradInvoiceWithoutPdfStaysAnInvoiceNumberOnly(): void
    {
        self::assertNull(ConradInvoicePdf::read('not a pdf', '1111111111'));
    }
}
