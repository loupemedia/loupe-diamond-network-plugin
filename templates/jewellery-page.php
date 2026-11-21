<?php
/**
 * Finished jewellery page template shell (PRD-020 CP 135).
 *
 * @package LoupeDiamondNetwork
 * @since   0.44.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$plugin = LDN_Plugin::instance();
$dispatcher = method_exists($plugin, 'jewellery_dispatcher') ? $plugin->jewellery_dispatcher() : null;
$ctx = $dispatcher ? $dispatcher->current_context() : null;
$summary = $dispatcher ? $dispatcher->primary_data() : null;

get_header();

if ($ctx instanceof LDN_Page_Context && is_array($summary)) {
    $renderer = new LDN_Jewellery_Renderer($plugin->data_fetcher(), $plugin->config());
    echo $renderer->render($ctx, $summary); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

get_footer();
