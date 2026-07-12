<?php
/**
 * Front-end asset registration — CP53 page chrome stylesheets.
 *
 * Loads shared.css on every LDN price route, plus an optional family stylesheet
 * at assets/css/families/{template_folder}.css when that file exists (no PHP
 * registry — convention matches config/sites/*.yaml template_folder).
 *
 * @package LoupeDiamondNetwork
 * @since   0.1.0
 */

if (!defined('ABSPATH')) {
	exit;
}

final class LDN_Assets {

	/**
	 * Schedule styles for the current LDN request (call from template_include).
	 *
	 * @param LDN_Page_Context $ctx
	 * @param LDN_Config       $config
	 * @return void
	 */
	public static function register_enqueue(LDN_Page_Context $ctx, LDN_Config $config) {
		add_action(
			'wp_enqueue_scripts',
			function () use ($ctx, $config) {
				self::enqueue($ctx, $config);
			}
		);
	}

	/**
	 * Enqueue shared + family stylesheets when the filter allows.
	 *
	 * @param LDN_Page_Context $ctx
	 * @param LDN_Config       $config
	 * @return void
	 */
	public static function enqueue(LDN_Page_Context $ctx, LDN_Config $config) {
		if (!apply_filters('ldn_enqueue_styles', true, $ctx)) {
			return;
		}

		$version = defined('LDN_VERSION') ? LDN_VERSION : '0.1.0';
		$base_url = defined('LDN_PLUGIN_URL') ? LDN_PLUGIN_URL : '';
		$css_dir = (defined('LDN_PLUGIN_DIR') ? LDN_PLUGIN_DIR : '') . 'assets/css/';

		if ($base_url === '' || !is_dir($css_dir)) {
			return;
		}

		wp_enqueue_style(
			'ldn-shared',
			$base_url . 'assets/css/shared.css',
			array(),
			$version
		);

		wp_enqueue_script(
			'ldn-chart-errors',
			$base_url . 'assets/js/chart-errors.js',
			array(),
			$version,
			true
		);

		if (in_array($ctx->page_level, array('size-comparison-tool', 'size-shape-hub'), true)) {
			wp_enqueue_script(
				'ldn-size-faceted-overlay',
				$base_url . 'assets/js/size-faceted-overlay.js',
				array(),
				$version,
				true
			);
			wp_enqueue_script(
				'ldn-size-checker',
				$base_url . 'assets/js/size-checker.js',
				array('ldn-size-faceted-overlay'),
				$version,
				true
			);
		}

		$deps = array('ldn-shared');
		$webfont_url = self::google_fonts_url_for_site($ctx->site_id, $config);
		if ($webfont_url !== null && apply_filters('ldn_enqueue_webfonts', true, $ctx)) {
			wp_enqueue_style(
				'ldn-webfonts',
				$webfont_url,
				array(),
				null
			);
			$deps = array('ldn-webfonts', 'ldn-shared');
		}

		$family = self::family_stylesheet($ctx->site_id, $config, $css_dir);
		if ($family !== null) {
			wp_enqueue_style(
				'ldn-family',
				$base_url . 'assets/css/' . $family,
				$deps,
				$version
			);
		}
	}

	/**
	 * Build a Google Fonts CSS2 URL from page_chrome.webfont_families.
	 *
	 * @param string     $site_id
	 * @param LDN_Config $config
	 * @return string|null
	 */
	public static function google_fonts_url_for_site($site_id, LDN_Config $config) {
		$profile = $config->get_content_profile($site_id);
		if (!is_array($profile)) {
			return null;
		}
		$chrome = isset($profile['page_chrome']) && is_array($profile['page_chrome'])
			? $profile['page_chrome']
			: array();
		$families = isset($chrome['webfont_families']) && is_array($chrome['webfont_families'])
			? $chrome['webfont_families']
			: array();

		return self::google_fonts_url($families);
	}

	/**
	 * @param array<int, mixed> $families Human-readable family names from page_chrome.
	 * @return string|null
	 */
	public static function google_fonts_url(array $families) {
		$parts = array();
		foreach ($families as $family) {
			if (!is_string($family)) {
				continue;
			}
			$slug = self::sanitize_webfont_family_name($family);
			if ($slug === '') {
				continue;
			}
			$parts[] = 'family=' . rawurlencode($slug) . ':wght@400;600;700';
		}
		if ($parts === array()) {
			return null;
		}

		return 'https://fonts.googleapis.com/css2?' . implode('&', $parts) . '&display=swap';
	}

	/**
	 * @param string $family
	 * @return string Slug safe for Google Fonts API, or ''.
	 */
	private static function sanitize_webfont_family_name($family) {
		$family = trim($family);
		if ($family === '' || strlen($family) > 80) {
			return '';
		}
		if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\\s\\-]*$/', $family)) {
			return '';
		}
		return $family;
	}

	/**
	 * Resolve the family CSS path relative to assets/css/, or null when absent.
	 *
	 * Convention: config/sites/{site}.yaml ``template_folder`` →
	 * ``families/{template_folder}.css`` (e.g. loupe → families/loupe.css).
	 *
	 * @param string     $site_id
	 * @param LDN_Config $config
	 * @param string     $css_dir Absolute path to assets/css (trailing slash optional).
	 * @return string|null Path relative to assets/css e.g. "families/loupe.css".
	 */
	public static function family_stylesheet($site_id, LDN_Config $config, $css_dir = '') {
		$site = $config->get_site($site_id);
		if ($site === null) {
			return null;
		}

		$folder = isset($site['template_folder']) ? (string) $site['template_folder'] : '';
		$folder = preg_replace('/[^a-z0-9\-]/', '', strtolower($folder));
		if ($folder === '') {
			return null;
		}

		if ($css_dir === '') {
			$css_dir = (defined('LDN_PLUGIN_DIR') ? LDN_PLUGIN_DIR : '') . 'assets/css/';
		}
		$css_dir = rtrim($css_dir, '/') . '/';

		$relative = 'families/' . $folder . '.css';
		return is_readable($css_dir . $relative) ? $relative : null;
	}
}
