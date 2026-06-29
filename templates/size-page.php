<?php
/**
 * Size page template shell.
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

$plugin = LDN_Plugin::instance();
$dispatcher = $plugin->size_dispatcher();
$ctx = $dispatcher ? $dispatcher->current_context() : null;
$summary = $dispatcher ? $dispatcher->primary_data() : null;

get_header();

if ($ctx instanceof LDN_Page_Context && is_array($summary)) {
    $renderer = new LDN_Size_Renderer($plugin->data_fetcher(), $plugin->config());
    echo $renderer->render($ctx, $summary); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

get_footer();
