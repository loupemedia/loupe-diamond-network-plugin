<?php
/**
 * Staging diagnostics panel (what's missing on this page).
 *
 * Rendered on the front end when LDN_Environment::is_staging(). Never shown on
 * production.
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Diagnostics {

    /** @var LDN_Page_Context|null */
    private static $context = null;

    /** @var string[] */
    private static $notes = array();

    /** @var array<int, array<string, mixed>>|null */
    private static $artefact_reports = null;

    /**
     * @param LDN_Page_Context $ctx
     * @return void
     */
    public static function set_context(LDN_Page_Context $ctx) {
        self::$context = $ctx;
    }

    /**
     * @param string $message
     * @return void
     */
    public static function note($message) {
        self::$notes[] = (string) $message;
    }

    /**
     * @return LDN_Page_Context|null
     */
    public static function context() {
        return self::$context;
    }

    /**
     * Build artefact probe reports for the current context.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function artefact_reports() {
        if (self::$artefact_reports !== null) {
            return self::$artefact_reports;
        }
        self::$artefact_reports = array();
        if (!(self::$context instanceof LDN_Page_Context)) {
            return self::$artefact_reports;
        }

        $fetcher = LDN_Plugin::instance()->data_fetcher();
        foreach (self::artefact_ids_for_level(self::$context->page_level) as $artefact_id) {
            self::$artefact_reports[] = $fetcher->probe_artefact($artefact_id, self::$context);
        }
        return self::$artefact_reports;
    }

    /**
     * @param string $page_level
     * @return string[]
     */
    public static function artefact_ids_for_level($page_level) {
        $common = array('summary_data_json', 'price_graph_json', 'distribution_json', 'static_content_json', 'individual_content_json');
        switch ($page_level) {
            case 'shape':
                return $common;
            case 'all-shapes':
                return array('shapes_ranking_json', 'static_content_json');
            case 'diamond-type':
                return array('type_summary_json', 'static_content_json');
            case 'top-level':
                return array('market_overview_json', 'static_content_json');
            default:
                return $common;
        }
    }

    /**
     * HTML panel for wp_footer (staging only).
     *
     * @return string
     */
    public static function render_panel() {
        if (!LDN_Environment::is_staging()) {
            return '';
        }
        if (!(self::$context instanceof LDN_Page_Context)) {
            return '';
        }

        $ctx = self::$context;
        $rollout = LDN_Plugin::instance()->rollout();
        $reports = self::artefact_reports();
        $primary_id = isset(LDN_Dispatcher::PRIMARY_ARTEFACT[$ctx->page_level])
            ? LDN_Dispatcher::PRIMARY_ARTEFACT[$ctx->page_level]
            : null;

        $html = '<aside class="ldn-staging-diagnostics" style="margin:2rem 0;padding:1rem;border:2px dashed #d63638;background:#fff8f0;font:13px/1.5 monospace;">';
        $html .= '<p style="margin:0 0 .75rem;font:bold 14px sans-serif;">LDN staging diagnostics</p>';
        $html .= '<p style="margin:0 0 .5rem;">Environment: <strong>' . esc_html(LDN_Environment::current()) . '</strong>';
        if ($rollout instanceof LDN_Rollout_Reader) {
            $html .= ' · Rollout v' . esc_html((string) $rollout->current_version());
            if ($rollout->is_test_only($ctx->country_code, 'price')) {
                $html .= ' · <strong>test_only</strong>';
            }
        }
        $html .= '</p>';
        $html .= '<p style="margin:0 0 .5rem;">Context: ' . esc_html($ctx->site_id) . ' / ' . esc_html($ctx->page_level)
            . ' / ' . esc_html($ctx->country_code);
        if ($ctx->diamond_type) {
            $html .= ' / ' . esc_html($ctx->diamond_type);
        }
        if ($ctx->carat) {
            $html .= ' / ' . esc_html($ctx->carat) . 'ct';
        }
        if ($ctx->shape) {
            $html .= ' / ' . esc_html($ctx->shape);
        }
        $html .= '</p>';

        if (self::$notes !== array()) {
            $html .= '<ul style="margin:0 0 .75rem;padding-left:1.2rem;">';
            foreach (self::$notes as $note) {
                $html .= '<li>' . esc_html($note) . '</li>';
            }
            $html .= '</ul>';
        }

        $html .= '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
        $html .= '<thead><tr><th style="text-align:left;border-bottom:1px solid #ccc;">Artefact</th>'
            . '<th style="text-align:left;border-bottom:1px solid #ccc;">Status</th>'
            . '<th style="text-align:left;border-bottom:1px solid #ccc;">URL</th></tr></thead><tbody>';
        foreach ($reports as $row) {
            $is_primary = ($primary_id !== null && $row['artefact_id'] === $primary_id);
            $label = $row['artefact_id'] . ($is_primary ? ' (primary)' : '');
            $status = $row['ok'] ? 'ok' : (string) $row['reason'];
            $style = $row['ok'] ? '' : 'color:#b32d2e;font-weight:bold;';
            $html .= '<tr><td style="padding:4px 8px 4px 0;vertical-align:top;">' . esc_html($label) . '</td>';
            $html .= '<td style="padding:4px 8px 4px 0;vertical-align:top;' . esc_attr($style) . '">' . esc_html($status) . '</td>';
            $html .= '<td style="padding:4px 0;vertical-align:top;word-break:break-all;">';
            if (!empty($row['url'])) {
                $html .= '<a href="' . esc_url($row['url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($row['url']) . '</a>';
            } else {
                $html .= '—';
            }
            $html .= '</td></tr>';
        }
        $html .= '</tbody></table>';
        $html .= '<p style="margin:.75rem 0 0;font-size:11px;color:#666;">Visible on staging only. Pull fresh rollout: wp-admin → Tools → Loupe Diamond Network.</p>';
        $html .= '</aside>';
        return $html;
    }

    /**
     * @return void
     */
    public static function reset() {
        self::$context = null;
        self::$notes = array();
        self::$artefact_reports = null;
    }
}
