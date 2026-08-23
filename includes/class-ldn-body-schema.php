<?php
/**
 * Suppress theme Blog microdata on LDN routes without clearing is_home().
 *
 * @package LoupeDiamondNetwork
 * @since   0.39.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Body_Schema {

    /**
     * @return void
     */
    public function register() {
        add_filter('generate_show_schema_markup', array($this, 'suppress_theme_blog_markup'), 10, 2);
        add_filter('body_class', array($this, 'body_class'), 20);
    }

    /**
     * GeneratePress emits itemtype="https://schema.org/Blog" on is_home().
     *
     * @param bool  $show
     * @param mixed $context
     * @return bool
     */
    public function suppress_theme_blog_markup($show, $context = null) {
        if ($this->is_ldn_html_route()) {
            return false;
        }
        return $show;
    }

    /**
     * Replace the blog-index body class with a neutral LDN marker.
     *
     * @param string[] $classes
     * @return string[]
     */
    public function body_class($classes) {
        if (!$this->is_ldn_html_route()) {
            return $classes;
        }

        $classes = array_values(array_diff($classes, array('blog', 'home')));
        if (!in_array('ldn-page', $classes, true)) {
            $classes[] = 'ldn-page';
        }
        return $classes;
    }

    /**
     * @return bool
     */
    private function is_ldn_html_route() {
        if (!function_exists('get_query_var')) {
            return false;
        }
        $route = get_query_var('ldn_route');
        if (!is_string($route) || $route === '') {
            return false;
        }
        return $route !== 'sitemap';
    }
}
