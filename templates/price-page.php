<?php
/**
 * Price page template — CP54 SSR (all page levels).
 *
 * All price routes render server-side via LDN_Renderer (crawlable stats, inline
 * Plotly charts, JSON-LD). Page composition is config-driven per site family.
 *
 * Available via the dispatcher:
 *   $ctx  = LDN_Page_Context for this request
 *   $data = primary artefact payload (array)
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

$dispatcher = LDN_Plugin::instance()->dispatcher();
$ctx = $dispatcher ? $dispatcher->current_context() : null;

get_header();

if ($ctx instanceof LDN_Page_Context) {
    echo LDN_Plugin::instance()->renderer()->render($ctx); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within renderer
}

get_footer();
