<?php
/**
 * Calculator destination page renderer.
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once LDN_PLUGIN_DIR . 'components/trait-ldn-chrome.php';
require_once LDN_PLUGIN_DIR . 'components/trait-ldn-data.php';
require_once LDN_PLUGIN_DIR . 'components/trait-ldn-price-calculator.php';

final class LDN_Calculator_Renderer {
    use LDN_Trait_Chrome;
    use LDN_Trait_Data;
    use LDN_Trait_Price_Calculator;

    /** @var array<string, string> Mirrors LDN_Renderer::CURRENCY_SYMBOLS for trait-ldn-data. */
    const CURRENCY_SYMBOLS = array(
        'USD' => '$', 'AUD' => 'A$', 'CAD' => 'C$', 'NZD' => 'NZ$', 'SGD' => 'S$',
        'HKD' => 'HK$', 'GBP' => '£', 'EUR' => '€', 'JPY' => '¥', 'INR' => '₹',
        'ZAR' => 'R', 'BRL' => 'R$', 'MXN' => 'MX$', 'TRY' => '₺', 'ILS' => '₪',
        'KRW' => '₩', 'AED' => 'AED ', 'SAR' => 'SAR ', 'CHF' => 'CHF ',
        'DKK' => 'kr ', 'SEK' => 'kr ', 'NOK' => 'kr ',
    );

    /**
     * Sections rendered when a profile declares no `page_structure.calculator.sections`.
     * The tool is the reason the page exists, so an unconfigured site gets the tool
     * rather than a blank page; every other block is opt-in per site.
     *
     * @var string[]
     */
    const DEFAULT_SECTIONS = array('calculator_tool');

    /** @var LDN_Data_Fetcher */
    private $fetcher;

    /** @var LDN_Config */
    private $config;

    /**
     * @param LDN_Data_Fetcher $fetcher
     * @param LDN_Config       $config
     */
    public function __construct(LDN_Data_Fetcher $fetcher, LDN_Config $config) {
        $this->fetcher = $fetcher;
        $this->config = $config;
    }

    /**
     * @param LDN_Page_Context $ctx
     * @return string
     */
    public function render(LDN_Page_Context $ctx) {
        $layout = $this->config->get_page_layout($ctx->site_id, 'calculator', $ctx->country_code);
        $sections = isset($layout['sections']) && $layout['sections'] !== array()
            ? $layout['sections']
            : self::DEFAULT_SECTIONS;
        $section_bands = isset($layout['section_bands']) && is_array($layout['section_bands'])
            ? $layout['section_bands']
            : array();

        $bag = array('manifest' => $this->default_manifest($ctx));

        // Render first, then assign bands from what actually reached the page, so a
        // section that yields nothing cannot leave two coloured bands adjacent.
        $rendered = array();
        foreach ($sections as $section_id) {
            $html = $this->render_calculator_section((string) $section_id, $ctx, $bag);
            if ($html === '') {
                continue;
            }
            $rendered[] = array(
                'html'     => $html,
                'declared' => isset($section_bands[(string) $section_id])
                    ? (string) $section_bands[(string) $section_id]
                    : '',
            );
        }
        if ($rendered === array()) {
            return '';
        }

        $body = '';
        $count = count($rendered);
        $previous_band = '';
        for ($i = 0; $i < $count; $i++) {
            $band = $this->coerce_section_band(
                $rendered[$i]['declared'],
                $previous_band,
                $i === $count - 1
            );
            $body .= $this->apply_section_band($rendered[$i]['html'], $band);
            $previous_band = $band;
        }

        $title = '<h1 class="ldn-page-title">' . esc_html__('Diamond Price Calculator', 'loupe-diamond-network') . '</h1>';
        $lead = $this->destination_lead_html($ctx);

        // Same shell as pricing pages so brand_tokens (--ldn-primary / --ldn-accent)
        // reach the grade sliders. Without it, accent-color falls back to black.
        $profile = $this->profile($ctx);
        return '<div class="ldn-page-shell">'
            . '<main class="ldn-price-page ldn-calculator-page">'
            . $this->theme_style_block($profile)
            . '<div class="ldn-calculator-destination-page">'
            . $title . $lead . $body
            . '</div></main></div>';
    }

    /**
     * Short page lead under the H1: what the tool does, market scale, freshness.
     *
     * Pool size comes from market_overview_json (country-wide inventory), not the
     * default shape calculator manifest — that only counts one carat×shape cell set.
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    private function destination_lead_html(LDN_Page_Context $ctx) {
        $text = __(
            'Typical prices from live retailer listings for the specification you choose — '
            . 'or add a quote to see where it sits.',
            'loupe-diamond-network'
        );

        $overview = $this->market_overview_for_lead($ctx);
        $total = isset($overview['total_diamonds_tracked'])
            ? (int) $overview['total_diamonds_tracked']
            : 0;
        $date_raw = '';
        if (!empty($overview['analysis_date']) && is_string($overview['analysis_date'])) {
            $date_raw = $overview['analysis_date'];
        }
        $date_display = $date_raw !== '' ? $this->localised_date($date_raw) : '';

        if ($total > 0 && $date_display !== '') {
            $text .= ' ' . sprintf(
                /* translators: 1: formatted diamond count, 2: localised analysis date */
                __(
                    'Based on %1$s diamonds. Prices last updated %2$s.',
                    'loupe-diamond-network'
                ),
                number_format($total),
                $date_display
            );
        } elseif ($total > 0) {
            $text .= ' ' . sprintf(
                /* translators: %s: formatted diamond count */
                __('Based on %s diamonds.', 'loupe-diamond-network'),
                number_format($total)
            );
        } elseif ($date_display !== '') {
            $text .= ' ' . sprintf(
                /* translators: %s: localised analysis date */
                __('Prices last updated %s.', 'loupe-diamond-network'),
                $date_display
            );
        }

        return '<p class="ldn-calculator-destination__lead">' . esc_html($text) . '</p>';
    }

    /**
     * Country market overview for the calculator lead (total inventory + date).
     *
     * @param LDN_Page_Context $ctx
     * @return array
     */
    private function market_overview_for_lead(LDN_Page_Context $ctx) {
        $top_ctx = new LDN_Page_Context(
            $ctx->site_id,
            'top-level',
            $ctx->country_code,
            null,
            null,
            null,
            'price'
        );
        $overview = $this->fetcher->fetch_artefact('market_overview_json', $top_ctx);
        return is_array($overview) ? $overview : array();
    }

    /**
     * Dispatch one section id, or '' when unmapped or it has no data.
     *
     * Public so the section-routing contract is directly unit-testable, matching
     * LDN_Renderer::render_section().
     *
     * @param string           $section_id
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return string
     */
    public function render_calculator_section($section_id, LDN_Page_Context $ctx, array $bag) {
        $manifest = isset($bag['manifest']) && is_array($bag['manifest']) ? $bag['manifest'] : array();

        switch ($section_id) {
            case 'calculator_tool':
                return $this->calculator_destination_html($ctx, $manifest);
            case 'how_it_works':
                return $this->calculator_how_it_works_html($ctx);
            case 'what_drives_price':
                return $this->calculator_what_drives_price_html($ctx, $manifest);
            case 'consumer_guidance':
                return $this->calculator_consumer_guidance_html($ctx);
            default:
                return '';
        }
    }

    /**
     * Calculator controls + result only (for REST refresh).
     *
     * @param LDN_Page_Context $ctx
     * @param array            $manifest
     * @return string
     */
    public function render_panel_html(LDN_Page_Context $ctx, array $manifest) {
        return $this->price_calculator_panel_from_manifest($ctx, $manifest);
    }

    /**
     * @param LDN_Page_Context $ctx
     * @return string
     */
    public function render_head_content(LDN_Page_Context $ctx) {
        $description = __(
            'Free diamond price calculator with daily-refreshed market data. '
            . 'Check typical prices for any carat, shape, colour and clarity, or compare a quote.',
            'loupe-diamond-network'
        );
        return '<meta name="description" content="' . esc_attr($description) . '">';
    }

    /**
     * @param LDN_Page_Context $ctx
     * @return array
     */
    private function default_manifest(LDN_Page_Context $ctx) {
        $shape_ctx = new LDN_Page_Context(
            $ctx->site_id,
            'shape',
            $ctx->country_code,
            'natural',
            '1',
            'round',
            'price'
        );
        $manifest = $this->fetcher->fetch_artefact('price_calculator_json', $shape_ctx);
        return is_array($manifest) ? $manifest : array();
    }
}
