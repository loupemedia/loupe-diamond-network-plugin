<?php
/**
 * Calculator destination page template.
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

$plugin = LDN_Plugin::instance();
$dispatcher = class_exists('LDN_Calculator_Module') ? LDN_Calculator_Module::dispatcher() : null;
$ctx = $dispatcher ? $dispatcher->current_context() : null;

get_header();

if ($ctx instanceof LDN_Page_Context) {
    $renderer = new LDN_Calculator_Renderer($plugin->data_fetcher(), $plugin->config());
    echo $renderer->render($ctx); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

get_footer();
