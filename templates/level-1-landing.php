<?php
/**
 * Level 1 — top-level diamond prices landing.
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
    echo LDN_Plugin::instance()->renderer()->render($ctx); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

get_footer();
