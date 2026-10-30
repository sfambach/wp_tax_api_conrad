<?php declare(strict_types=1);

namespace TaxApiConrad\WordPress;

use TaxApiConrad\Core\ConradMailParser;
use Taxmod\Core\Import\Mail\OrderMailParser;
use Taxmod\WordPress\Import\Documents;

/**
 * The boundary: registers the Conrad mail reader with Taxmod Mail (filter `taxmail_parsers`) and a tab on the
 * modeller's Configuration page whose part «Mail» names what it reads (D-1006, D-1007).
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/import/module-schnitt.md
 */
final class Plugin
{
    public const VERSION = '0.0.2';
    public const TAB     = 'conrad';

    public static function boot(): void
    {
        add_action('plugins_loaded', static function (): void {
            load_plugin_textdomain('taxapiconrad', false, dirname(plugin_basename(dirname(__DIR__, 2) . '/wp-tax-api-conrad.php')) . '/languages');

            // The contract lives in the modeller (0.1.81 or later); without it there is nothing to register with.
            if (! interface_exists(OrderMailParser::class)) {
                add_action('admin_notices', static fn () => print '<div class="notice notice-warning"><p><strong>Taxmod Conrad:</strong> '
                    . esc_html__('needs the Taxonomy Modeller 0.1.81 or later and Taxmod Mail 0.6.0 or later.', 'taxapiconrad') . '</p></div>');

                return;
            }

            // Invoice PDFs go to the modeller's encrypted document store (0.1.94 or later, D-1023); without it only the facts are kept.
            $reader = new ConradMailParser(self::VERSION, class_exists(Documents::class) ? Documents::put(...) : null);
            add_filter('taxmail_parsers', static fn (array $parsers): array => [...$parsers, $reader]);
            add_filter('taxmod_config_tabs', static fn (array $tabs): array => $tabs + [self::TAB => [
                'title'   => 'Conrad',
                'version' => self::VERSION,
                'reader'  => $reader,
                'guide'   => self::guide(...),
                'render'  => static fn (): string => '',
                'save'    => static function (array $post): void {
                },
            ]], 30);
        });
    }

    /** The box «How to connect» beside the tab (D-963). */
    public static function guide(): string
    {
        return '<ol><li>' . esc_html__('Let Conrad send its order mails to the mailbox of Taxmod Mail (or forward them there).', 'taxapiconrad') . '</li><li>'
            . esc_html__('Keep this module active: Taxmod Mail hands every Conrad mail to it, and the proposals appear in the Inbox.', 'taxapiconrad') . '</li></ol><p>'
            . esc_html__('An invoice becomes a record «Rechnung» linked to its order: number, date, amount, paid or open, and the PDF, which is kept encrypted.', 'taxapiconrad') . '</p>';
    }
}
