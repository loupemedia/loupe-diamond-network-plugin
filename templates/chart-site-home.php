<?php
/**
 * Chart-reference site marketing shell (domain root /).
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

$plugin = LDN_Plugin::instance();
$site_id = $plugin->site_id();
if ($site_id === null) {
    return;
}

get_header();

$renderer = new LDN_Size_Renderer($plugin->data_fetcher(), $plugin->config());
echo $renderer->render_marketing_home($site_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

get_footer();
