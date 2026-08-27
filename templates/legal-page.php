<?php
/**
 * Legal page template (PRD-021 CP137).
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

$plugin = LDN_Plugin::instance();
$dispatcher = class_exists('LDN_Standard_Pages_Module')
    ? LDN_Standard_Pages_Module::dispatcher()
    : null;
$ctx = $dispatcher ? $dispatcher->current_context() : null;
$page_id = $dispatcher ? $dispatcher->current_page_id() : '';

get_header();

if ($ctx instanceof LDN_Page_Context && $page_id !== '') {
    $renderer = new LDN_Standard_Pages_Renderer($plugin->config());
    echo $renderer->render($ctx, $page_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

get_footer();
