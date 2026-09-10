<?php
/**
 * Upsell_Handler class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Upsell
 */

declare(strict_types=1);

namespace PLLAT\Upsell\Handlers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Upsell\Services\Upsell_Service;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Filter;
use XWP\DI\Decorators\Handler;

/**
 * Adds the Pro tab and the provider note to the settings page.
 */
#[Handler( tag: 'init', priority: 11, context: Handler::CTX_ADMIN )]
class Upsell_Handler {
    public const PROVIDER_NOTE_FIELD = 'pllat_pro_providers_note';

    /**
     * Constructor.
     *
     * @param Upsell_Service $upsell The upgrade URL owner.
     */
    public function __construct(
        protected Upsell_Service $upsell,
    ) {
    }

    /**
     * Add the Pro tab as the last settings tab.
     *
     * @param array<string,string> $tabs Tab labels keyed by slug, in display order.
     * @return array<string,string>
     */
    #[Filter( tag: 'pllat_settings_tabs' )]
    public function add_pro_tab( array $tabs ): array {
        return $tabs + array( 'pro' => \__( 'Pro', 'ai-translation-for-polylang' ) );
    }

    /**
     * Add the provider note right under the provider select on the General tab.
     *
     * @param array<string,array{title: string, callback: callable, args?: array<string,mixed>}> $fields Fields in render order.
     * @return array<string,array{title: string, callback: callable, args?: array<string,mixed>}>
     */
    #[Filter( tag: 'pllat_settings_general_fields' )]
    public function add_provider_note( array $fields ): array {
        $note = array(
            self::PROVIDER_NOTE_FIELD => array(
                'args'     => array( 'class' => 'pllat-field-note' ),
                'callback' => array( $this, 'render_provider_note' ),
                'title'    => '',
            ),
        );

        $position = \array_search( 'pllat_translator_api', \array_keys( $fields ), true );
        if ( false === $position ) {
            return $fields + $note;
        }

        return \array_slice( $fields, 0, $position + 1, true )
            + $note
            + \array_slice( $fields, $position + 1, null, true );
    }

    /**
     * Render the provider note.
     *
     * @return void
     */
    public function render_provider_note(): void {
        ?>
        <p class="description">
            <?php
            \esc_html_e(
                'The Pro edition adds Anthropic Claude, Google Gemini and OpenRouter with a free choice of model.',
                'ai-translation-for-polylang',
            );
            ?>
        </p>
        <?php
    }

    /**
     * Render the Pro tab.
     *
     * Rendered by Settings_Form for the pro tab. The list mirrors the
     * "What the Pro edition adds" section of the wordpress.org readme.
     *
     * @return void
     */
    #[Action( tag: 'pllat_settings_render_tab_pro' )]
    public function render_pro_tab(): void {
        $features = array(
            \__(
                'Bulk translation: pick post types, taxonomies and languages on the dashboard and translate your whole site in one run.',
                'ai-translation-for-polylang',
            ),
            \__(
                'Auto-Translate 24/7 (upcoming): new and edited content is translated automatically.',
                'ai-translation-for-polylang',
            ),
            \__(
                'Internal link rewriting: links inside translated content point to the translated pages.',
                'ai-translation-for-polylang',
            ),
            \__(
                'Elementor, Bricks, ACF and WooCommerce: page builder layouts, custom field groups and products are translated in place.',
                'ai-translation-for-polylang',
            ),
            \__(
                'Custom field management: scan the custom fields of each post type and decide per field whether it is translated, copied or ignored.',
                'ai-translation-for-polylang',
            ),
            \__(
                'Polylang string translations for theme and plugin strings.',
                'ai-translation-for-polylang',
            ),
            \__(
                'Anthropic Claude, Google Gemini and OpenRouter next to OpenAI, with a free choice of model.',
                'ai-translation-for-polylang',
            ),
            \__(
                'Site-wide AI context and custom instructions applied to every translation.',
                'ai-translation-for-polylang',
            ),
            \__(
                'SEO meta for Yoast SEO, Rank Math, SEOPress and All in One SEO.',
                'ai-translation-for-polylang',
            ),
            \__(
                'Premium support and automatic updates through your account on our website.',
                'ai-translation-for-polylang',
            ),
        );
        $url      = $this->upsell->upgrade_url( 'settings-pro-tab' );
        $heading  = \__( 'What the Pro edition adds', 'ai-translation-for-polylang' );
        ?>
        <div class="pllat-support-tab-wrapper">
            <div class="pllat-support-container">
                <div class="pllat-support-card">
                    <h2><?php echo \esc_html( $heading ); ?></h2>
                    <p>
                        <?php
                        \esc_html_e(
                            'AI Translation for Polylang Pro is a separate plugin sold on our website; it keeps your settings and existing translations and adds:',
                            'ai-translation-for-polylang',
                        );
                        ?>
                    </p>
                    <ul class="ul-disc">
                        <?php foreach ( $features as $feature ) : ?>
                            <li><?php echo \esc_html( $feature ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p>
                        <a href="<?php echo \esc_url( $url ); ?>"
                            class="button button-primary"
                            target="_blank"
                            rel="noopener">
                            <?php \esc_html_e( 'See Pro plans', 'ai-translation-for-polylang' ); ?>
                        </a>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }
}
