<?php
/**
 * Loose diamond shopping card page template shell (PRD-025 CP 166).
 *
 * @package LoupeDiamondNetwork
 * @since   0.45.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$plugin = LDN_Plugin::instance();
$dispatcher = method_exists($plugin, 'diamonds_dispatcher') ? $plugin->diamonds_dispatcher() : null;
$ctx = $dispatcher ? $dispatcher->current_context() : null;
$payload = $dispatcher ? $dispatcher->primary_data() : null;

get_header();

if ($ctx instanceof LDN_Page_Context && is_array($payload)) {
    $renderer = new LDN_Diamonds_Renderer($plugin->data_fetcher(), $plugin->config());
    echo $renderer->render($ctx, $payload); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

get_footer();
