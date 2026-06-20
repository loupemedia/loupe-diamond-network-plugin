<?php
/**
 * Price page template — CP54 SSR shape page.
 *
 * Shape pages render server-side via LDN_Renderer (crawlable stats, inline
 * Plotly charts, JSON-LD). Other page levels (all-shapes, diamond-type,
 * top-level) still fall through to a placeholder until their renderers land.
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
$data = $dispatcher ? $dispatcher->primary_data() : null;

get_header();

if ($ctx instanceof LDN_Page_Context) {
    if ($ctx->page_level === 'shape') {
        echo LDN_Plugin::instance()->renderer()->render($ctx); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within renderer
    } else {
        $bits = array_filter(array(
            ucfirst($ctx->page_level),
            strtoupper($ctx->country_code),
            $ctx->diamond_type,
            $ctx->carat !== null ? $ctx->carat . ' carat' : null,
            $ctx->shape,
        ), 'strlen');
        ?>
        <main class="ldn-price-page" style="max-width:840px;margin:2rem auto;padding:0 1rem;">
            <h1><?php echo esc_html(implode(' · ', $bits)); ?> diamond prices</h1>
            <p style="color:#888;font-size:.85rem;">
                <?php echo esc_html('Loupe Diamond Network — placeholder template. Site: ' . $ctx->site_id); ?>
            </p>
            <?php // Placeholder: dump the primary payload so the path is verifiable. ?>
            <pre style="background:#0d1117;color:#c9d1d9;padding:1rem;overflow:auto;border-radius:6px;"><?php
                echo esc_html(wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            ?></pre>
        </main>
        <?php
    }
}

get_footer();
