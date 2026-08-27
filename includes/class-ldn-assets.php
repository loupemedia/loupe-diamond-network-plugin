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
	 * Script handles that must not be delayed/minified by WP Rocket.
	 *
	 * Delay JS wraps these as type=rocketlazyloadscript until first interaction,
	 * so grade sliders never receive input handlers while shape/carat REST still
	 * appears to "work" after Rocket finally loads the file.
	 *
	 * @var string[]
	 */
	const INTERACTIVE_SCRIPT_HANDLES = array(
		'ldn-chart-init',
		'ldn-price-calculator',
		'ldn-price-calculator-destination',
		'ldn-size-checker',
		'ldn-size-carat-explorer',
		'ldn-type-carat-lookup',
		'ldn-nat-lab-toggle',
		'ldn-analytics',
	);

	/**
	 * Register WP Rocket / optimizers compat once (plugins_loaded).
	 *
	 * @return void
	 */
	public static function register_performance_compat() {
		static $done = false;
		if ($done) {
			return;
		}
		$done = true;

		add_filter('rocket_delay_js_exclusions', array(__CLASS__, 'exclude_interactive_scripts_from_rocket'));
		add_filter('rocket_exclude_defer_js', array(__CLASS__, 'exclude_interactive_scripts_from_rocket'));
		add_filter('rocket_exclude_js', array(__CLASS__, 'exclude_interactive_script_paths_from_rocket'));
		add_filter('script_loader_tag', array(__CLASS__, 'mark_interactive_scripts_no_minify'), 10, 2);
	}

	/**
	 * Patterns matched against script URLs / handles for Delay + Defer exclusions.
	 *
	 * @param string[] $excluded
	 * @return string[]
	 */
	public static function exclude_interactive_scripts_from_rocket($excluded) {
		if (!is_array($excluded)) {
			$excluded = array();
		}
		$excluded[] = 'ldn-chart-init';
		$excluded[] = 'chart-init';
		$excluded[] = 'ldn-price-calculator';
		$excluded[] = 'price-calculator';
		$excluded[] = 'ldn-size-checker';
		$excluded[] = 'ldn-size-carat-explorer';
		$excluded[] = 'ldn-type-carat-lookup';
		$excluded[] = 'ldn-nat-lab-toggle';
		$excluded[] = 'ldn-analytics';
		$excluded[] = '/loupe-diamond-network(?:-plugin)?/assets/js/price-calculator';
		$excluded[] = '/loupe-diamond-network(?:-plugin)?/assets/js/size-';
		$excluded[] = '/loupe-diamond-network(?:-plugin)?/assets/js/type-carat-lookup';
		$excluded[] = '/loupe-diamond-network(?:-plugin)?/assets/js/nat-lab-toggle';
		return $excluded;
	}

	/**
	 * Absolute-ish path patterns for rocket_exclude_js (minify).
	 *
	 * @param string[] $excluded
	 * @return string[]
	 */
	public static function exclude_interactive_script_paths_from_rocket($excluded) {
		if (!is_array($excluded)) {
			$excluded = array();
		}
		$excluded[] = '/wp-content/plugins/loupe-diamond-network-plugin/assets/js/chart-init.js';
		$excluded[] = '/wp-content/plugins/loupe-diamond-network/assets/js/chart-init.js';
		$excluded[] = '/wp-content/plugins/loupe-diamond-network-plugin/assets/js/price-calculator(.*).js';
		$excluded[] = '/wp-content/plugins/loupe-diamond-network/assets/js/price-calculator(.*).js';
		$excluded[] = '/wp-content/plugins/loupe-diamond-network-plugin/assets/js/size-(.*).js';
		$excluded[] = '/wp-content/plugins/loupe-diamond-network/assets/js/size-(.*).js';
		$excluded[] = '/wp-content/plugins/loupe-diamond-network-plugin/assets/js/type-carat-lookup.js';
		$excluded[] = '/wp-content/plugins/loupe-diamond-network/assets/js/type-carat-lookup.js';
		$excluded[] = '/wp-content/plugins/loupe-diamond-network-plugin/assets/js/nat-lab-toggle.js';
		$excluded[] = '/wp-content/plugins/loupe-diamond-network/assets/js/nat-lab-toggle.js';
		$excluded[] = '/wp-content/plugins/loupe-diamond-network-plugin/assets/js/analytics.js';
		$excluded[] = '/wp-content/plugins/loupe-diamond-network/assets/js/analytics.js';
		return $excluded;
	}

	/**
	 * data-no-minify so WP Rocket leaves calculator/size scripts as-is.
	 *
	 * @param string $tag
	 * @param string $handle
	 * @return string
	 */
	public static function mark_interactive_scripts_no_minify($tag, $handle) {
		if (!in_array($handle, self::INTERACTIVE_SCRIPT_HANDLES, true)) {
			return $tag;
		}
		if (strpos($tag, 'data-no-minify=') !== false) {
			return $tag;
		}
		return str_replace('<script ', '<script data-no-minify="1" ', $tag);
	}

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
			'ldn-chart-init',
			$base_url . 'assets/js/chart-init.js',
			array(),
			$version,
			false
		);

		wp_enqueue_script(
			'ldn-chart-errors',
			$base_url . 'assets/js/chart-errors.js',
			array('ldn-chart-init'),
			$version,
			true
		);

		wp_enqueue_script(
			'ldn-ad-tracking',
			$base_url . 'assets/js/ad-tracking.js',
			array(),
			$version,
			true
		);

		$track_url = '';
		if (class_exists('LDN_Plugin')) {
			$ad_system = LDN_Plugin::instance()->ad_system();
			if ($ad_system !== null) {
				$track_url = $ad_system->tracker()->track_endpoint_url();
			}
		}
		wp_localize_script(
			'ldn-ad-tracking',
			'ldnAdTracking',
			array(
				'trackUrl' => $track_url,
			)
		);

		if ($config->size_url_layout($ctx->site_id) === 'shape_first'
			&& in_array($ctx->page_level, array('size-comparison', 'size-comparison-tool', 'size-shape-hub'), true)
		) {
			wp_enqueue_script(
				'ldn-size-faceted-overlay',
				$base_url . 'assets/js/size-faceted-overlay.js',
				array(),
				$version,
				true
			);
			if ($ctx->page_level === 'size-comparison') {
				wp_enqueue_script(
					'ldn-size-comparison-page',
					$base_url . 'assets/js/size-comparison-page.js',
					array('ldn-size-faceted-overlay'),
					$version,
					true
				);
			}
			if (in_array($ctx->page_level, array('size-comparison-tool', 'size-shape-hub'), true)) {
				wp_enqueue_script(
					'ldn-size-checker',
					$base_url . 'assets/js/size-checker.js',
					array('ldn-size-faceted-overlay'),
					$version,
					true
				);
				wp_localize_script(
					'ldn-size-checker',
					'ldnSizeChecker',
					array(
						'quarterImgUrl' => self::us_quarter_image_url(),
					)
				);
			}
		}

		if ($config->size_url_layout($ctx->site_id) === 'carat_first'
			&& $ctx->page_level === 'size-carat-hub'
		) {
			wp_enqueue_script(
				'ldn-size-faceted-overlay',
				$base_url . 'assets/js/size-faceted-overlay.js',
				array(),
				$version,
				true
			);
			wp_enqueue_script(
				'ldn-size-carat-explorer',
				$base_url . 'assets/js/size-carat-explorer.js',
				array('ldn-size-faceted-overlay'),
				$version,
				true
			);
		}

		// Gated on the widget rather than the page level so an unentitled site
		// never ships the file. The script exits on a missing manifest anyway,
		// but a script that can do nothing should not be downloaded.
		if (class_exists('LDN_Artefacts')) {
			$artefacts = new LDN_Artefacts($config);
			if ($artefacts->site_has_wp_widget($ctx->site_id, 'price_calculator', $ctx->page_level)) {
				wp_enqueue_script(
					'ldn-price-calculator',
					$base_url . 'assets/js/price-calculator.js',
					array(),
					$version,
					true
				);
			}
			if ($ctx->page_level === 'calculator') {
				wp_enqueue_script(
					'ldn-price-calculator-destination',
					$base_url . 'assets/js/price-calculator-destination.js',
					array('ldn-price-calculator'),
					$version,
					true
				);
				wp_localize_script(
					'ldn-price-calculator-destination',
					'ldnCalculatorDestination',
					array(
						'restUrl' => esc_url_raw(rest_url('ldn/v1/')),
					)
				);
			}
			if ($ctx->page_level === 'diamond-type') {
				wp_enqueue_script(
					'ldn-type-carat-lookup',
					$base_url . 'assets/js/type-carat-lookup.js',
					array(),
					$version,
					true
				);
				if ($artefacts->site_has_wp_widget($ctx->site_id, 'nat_lab_toggle', $ctx->page_level)) {
					wp_enqueue_script(
						'ldn-nat-lab-toggle',
						$base_url . 'assets/js/nat-lab-toggle.js',
						array(),
						$version,
						true
					);
				}
			}
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

		$heading_style_sheet = self::heading_style_stylesheet($ctx->site_id, $config, $css_dir);
		if ($heading_style_sheet !== null) {
			wp_enqueue_style(
				'ldn-heading-style',
				$base_url . 'assets/css/' . $heading_style_sheet,
				$deps,
				$version
			);
			$deps = array('ldn-heading-style');
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
	 * Resolve heading_style chrome CSS (e.g. editorial → families/editorial.css).
	 *
	 * @param string     $site_id
	 * @param LDN_Config $config
	 * @param string     $css_dir Absolute path to assets/css.
	 * @return string|null Path relative to assets/css.
	 */
	public static function heading_style_stylesheet($site_id, LDN_Config $config, $css_dir = '') {
		$profile = $config->get_content_profile($site_id);
		if (!is_array($profile)) {
			return null;
		}
		$chrome = isset($profile['page_chrome']) && is_array($profile['page_chrome'])
			? $profile['page_chrome']
			: array();
		$style = isset($chrome['heading_style']) ? (string) $chrome['heading_style'] : '';
		$style = preg_replace('/[^a-z0-9_]/', '', strtolower($style));
		if ($style === '' || $style === 'minimal') {
			return null;
		}

		if ($css_dir === '') {
			$css_dir = (defined('LDN_PLUGIN_DIR') ? LDN_PLUGIN_DIR : '') . 'assets/css/';
		}
		$css_dir = rtrim($css_dir, '/') . '/';

		$relative = 'families/' . str_replace('_', '-', $style) . '.css';
		return is_readable($css_dir . $relative) ? $relative : null;
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

	/**
	 * Public URL for the US quarter scale-reference PNG (cache-busted).
	 *
	 * @return string
	 */
	public static function us_quarter_image_url() {
		$base = defined('LDN_PLUGIN_URL') ? LDN_PLUGIN_URL : '';
		$version = defined('LDN_VERSION') ? LDN_VERSION : '0.1.0';
		if ($base === '') {
			return '';
		}

		return $base . 'assets/img/us-quarter.png?ver=' . rawurlencode($version);
	}
}
