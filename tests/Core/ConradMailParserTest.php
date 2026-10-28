<?php declare(strict_types=1);

namespace TaxApiConrad\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxApiConrad\Core\ConradMailParser;
use Taxmod\Core\Import\Mail\MimeParser;

/**
 * Each expectation is what the Python importer (legacy/ in Taxmod Mail) read from the same synthetic mail.
 */
final class ConradMailParserTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function read(string $fixture): array
    {
        $parser = new ConradMailParser('0.0.1');
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
}
