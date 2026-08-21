<?php
/**
 * Renderer component trait — split from class-ldn-renderer.php (CP53).
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

trait LDN_Trait_Charts {
    /**
     * Inline Plotly chart block from a Plotly payload, or '' when absent.
     *
     * When *$fallback* is supplied it is rendered **inside** the Plotly target
     * div with `ldn-chart-fallback--sr` so crawlers and assistive tech read the
     * factual summary while sighted readers are not shown a second median line
     * above the hero stat cards. `Plotly.newPlot()` clears the container before
     * drawing.
     *
     * @param mixed  $payload  Plotly figure (expects a `data` array).
     * @param string $dom_id
     * @param string $title
     * @param string $fallback Plain-text description of the chart's data.
     * @param string $fallback_class CSS class(es) on the fallback paragraph.
     * @return string
     */
    public function chart_html($payload, $dom_id, $title, $fallback = '', $fallback_class = 'ldn-chart-fallback') {
        if (!is_array($payload) || empty($payload['data']) || !is_array($payload['data'])) {
            return '';
        }

        $resolved_title = $this->chart_display_title($payload, $title);

        $data = wp_json_encode($payload['data'], self::JSON_SCRIPT_FLAGS);
        $layout = $this->prepare_inline_chart_layout(
            isset($payload['layout']) && is_array($payload['layout']) ? $payload['layout'] : array()
        );
        $layout_json = wp_json_encode($layout, self::JSON_SCRIPT_FLAGS);
        $cfg = wp_json_encode(isset($payload['config']) ? $payload['config'] : array('responsive' => true), self::JSON_SCRIPT_FLAGS);
        $dom_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $dom_id);

        $html = $this->plotly_loader();
        $html .= '<figure class="ldn-chart">';
        if ($resolved_title !== '') {
            $html .= '<h3 class="ldn-chart__title">' . esc_html($resolved_title) . '</h3>';
        }
        $fallback_html = (is_string($fallback) && $fallback !== '')
            ? '<p class="ldn-chart-fallback ldn-chart-fallback--sr">' . esc_html($fallback) . '</p>'
            : '';
        $html .= '<div id="' . esc_attr($dom_id) . '" class="ldn-chart-target">'
            . $fallback_html . '</div>';
        // data-ldn-chart marks this as the only script a client-side page swap
        // may re-run (nat-lab-toggle.js). Ad and analytics scripts in the same
        // subtree must not fire twice, so the swap needs a positive marker
        // rather than "every script in <main>".
        $html .= '<script data-ldn-chart="1">(function(){var run=function(){if(!window.LDN||!window.LDN.plotChart){return false;}'
            . 'window.LDN.plotChart('
            . wp_json_encode($dom_id)
            . ',' . $data . ',' . $layout_json . ',' . $cfg . ');return true;};'
            . 'if(!run()){var boot=function(){run();};'
            . 'document.addEventListener("DOMContentLoaded",boot);'
            . 'window.addEventListener("load",boot);}})();</script>';
        $html .= '</figure>';

        return $html;
    }

    /**
     * Visible chart title: Plotly layout.title.text when present, else the fallback.
     *
     * @param array  $payload
     * @param string $fallback
     * @return string
     */
    public function chart_display_title(array $payload, $fallback) {
        $layout = isset($payload['layout']) && is_array($payload['layout']) ? $payload['layout'] : array();
        if (isset($layout['title'])) {
            if (is_array($layout['title']) && isset($layout['title']['text'])) {
                $text = trim((string) $layout['title']['text']);
                if ($text !== '') {
                    return $text;
                }
            } elseif (is_string($layout['title'])) {
                $text = trim($layout['title']);
                if ($text !== '') {
                    return $text;
                }
            }
        }
        return is_string($fallback) ? trim($fallback) : '';
    }

    /**
     * Adjust Plotly layout for inline WP rendering (title in H3, not figure).
     *
     * C5 exports layouts with a large top margin for the in-chart title and period
     * toggles. LDN hides .gtitle and shows the title in an H3, so reclaim
     * vertical space and bump height slightly for readability.
     *
     * @param array $layout Plotly layout dict from price-graph.json.
     * @return array
     */
    public function prepare_inline_chart_layout(array $layout) {
        $margin = (isset($layout['margin']) && is_array($layout['margin']))
            ? $layout['margin']
            : array();
        $top = isset($margin['t']) ? (int) $margin['t'] : 150;
        if ($top > 80) {
            $margin['t'] = 72;
        }
        $layout['margin'] = $margin;

        $height = isset($layout['height']) ? (int) $layout['height'] : 390;
        if ($height < 440) {
            $layout['height'] = 440;
        }

        if (isset($layout['title'])) {
            if (is_array($layout['title'])) {
                $layout['title']['text'] = '';
            } else {
                $layout['title'] = array('text' => '');
            }
        }

        return $layout;
    }

    /**
     * Plotly CDN <script>, emitted at most once per request.
     *
     * @return string
     */
    private function plotly_loader() {
        if ($this->plotly_emitted) {
            return '';
        }
        $this->plotly_emitted = true;
        return '<script src="' . esc_url(self::PLOTLY_CDN) . '"></script>';
    }
}
