<?php
/**
 * Price calculator / position checker (`price_calculator` widget, CP 123).
 *
 * Reads the cell manifest C5.9 publishes as price-calculator.json: percentiles and
 * sample size per colour group x clarity group x cut grade for this page's shape,
 * carat and diamond type.
 *
 * One component, two answers behind the same input. Leave the price field blank
 * and it answers "what should this cost"; enter a quoted price and it answers
 * where that price sits against the same cell. The checker is the calculator with
 * the price filled in, which is why there is no second widget.
 *
 * Two rules the markup exists to honour:
 *
 * 1. The manifest is an argument, never fetched from page context in here. A
 *    destination page (a ringspo.com calculator page, a standalone calculator
 *    site) has no shape or carat of its own and supplies the manifest for
 *    whatever the reader picks. See docs/architecture/price-position-checker.md
 *    § Destination pages.
 * 2. The default cell is rendered server-side as real prose, so the page answers
 *    with JavaScript off (Engineering Principle 10). Result states are not
 *    indexable and get no URLs of their own.
 *
 * Reader-facing strings all live here rather than in the JavaScript, and travel
 * to the browser inside the manifest's `labels`, so translation stays in PHP.
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

trait LDN_Trait_Price_Calculator {
    /**
     * Percentile keys, cheapest first. The manifest publishes these five; we do
     * not interpolate between them into a single integer percentile, because
     * five points cannot support that precision. The reader gets a band instead.
     */
    private static $PRICE_CALC_PERCENTILES = array('p10', 'p25', 'p50', 'p75', 'p90');

    /**
     * "Diamond price calculator" block, or '' when the widget is off for this
     * site/level or the manifest is missing or unusable.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return string
     */
    public function price_calculator_html(LDN_Page_Context $ctx, array $bag) {
        $manifest = isset($bag['price_calculator']) && is_array($bag['price_calculator'])
            ? $bag['price_calculator']
            : array();

        return $this->price_calculator_from_manifest($ctx, $manifest, 'shape');
    }

    /**
     * Shape-switching calculator hub for all-shapes pages.
     *
     * Fetches a price-calculator.json manifest per ranked shape and renders the
     * default shape inline with pills to switch between shapes on the same page.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return string
     */
    public function all_shapes_calculator_html(LDN_Page_Context $ctx, array $bag) {
        $artefacts = new LDN_Artefacts($this->config);
        if (!$artefacts->site_has_wp_widget($ctx->site_id, 'price_calculator', 'all-shapes')) {
            return '';
        }
        $presentation = $artefacts->wp_widget_presentation(
            $ctx->site_id,
            'price_calculator',
            'all-shapes'
        );
        if ($presentation === null || $presentation === '' || $presentation === 'disabled') {
            return '';
        }
        if (!$artefacts->site_has_wp_widget($ctx->site_id, 'price_calculator', 'shape')) {
            return '';
        }

        $payload = is_array($bag['ranking']) ? $bag['ranking'] : array();
        $rows = isset($payload['shapes']) && is_array($payload['shapes']) ? $payload['shapes'] : array();
        if (empty($rows)) {
            return '';
        }

        $carat_label = $this->format_carat_label($ctx->carat);
        $type_label = isset(self::$TYPE_LABELS[$ctx->diamond_type])
            ? self::$TYPE_LABELS[$ctx->diamond_type]
            : ucwords(str_replace('-', ' ', (string) $ctx->diamond_type));

        $heading = sprintf(
            /* translators: 1: carat label, 2: diamond type label */
            __('%1$s carat %2$s diamond price calculator', 'loupe-diamond-network'),
            $carat_label !== '' ? $carat_label : '1',
            strtolower($type_label)
        );

        $pills = '';
        $panels = '';
        $first_shape = null;

        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['shape'])) {
                continue;
            }
            $shape = (string) $row['shape'];
            $shape_ctx = new LDN_Page_Context(
                $ctx->site_id,
                'shape',
                $ctx->country_code,
                $ctx->diamond_type,
                $ctx->carat,
                $shape,
                $ctx->module
            );
            $manifest = $this->fetcher->fetch_artefact('price_calculator_json', $shape_ctx);
            if (!is_array($manifest) || empty($manifest)) {
                continue;
            }

            $panel = $this->price_calculator_panel_from_manifest($ctx, $manifest);
            if ($panel === '') {
                continue;
            }

            $shape_slug = sanitize_title($shape);
            if ($first_shape === null) {
                $first_shape = $shape_slug;
            }
            $active = $shape_slug === $first_shape;
            $pills .= '<button type="button" class="ldn-shape-calc-pill'
                . ($active ? ' ldn-shape-calc-pill--active' : '')
                . '" data-ldn-shape-calc-pill="' . esc_attr($shape_slug) . '">'
                . esc_html($shape) . '</button>';

            $panels .= '<div class="ldn-shape-calc-panel'
                . ($active ? ' ldn-shape-calc-panel--active' : '')
                . '" data-ldn-shape-calc-panel="' . esc_attr($shape_slug) . '"'
                . ($active ? '' : ' hidden')
                . '>' . $panel . '</div>';
        }

        if ($pills === '' || $panels === '') {
            return '';
        }

        return '<section class="ldn-section ldn-all-shapes-calculator" data-ldn-price-calculator-hub="1">'
            . '<h2>' . esc_html($heading) . '</h2>'
            . '<p class="ldn-all-shapes-calculator__lead">'
            . esc_html__(
                'Pick a shape, then choose colour and clarity to see typical prices or check a quote.',
                'loupe-diamond-network'
            )
            . '</p>'
            . '<nav class="ldn-shape-calc-pills" aria-label="'
            . esc_attr__('Calculator shape', 'loupe-diamond-network') . '">'
            . $pills
            . '</nav>'
            . '<div class="ldn-shape-calc-panels">' . $panels . '</div>'
            . '</section>';
    }

    /**
     * Render from an explicit manifest.
     *
     * Split out from price_calculator_html so a host with no shape/carat page
     * context can supply a manifest it resolved itself. Nothing in here reads
     * $ctx->shape or $ctx->carat: the specification comes from the manifest.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $manifest price-calculator.json payload
     * @return string
     */
    public function price_calculator_from_manifest(LDN_Page_Context $ctx, array $manifest, $page_level = null) {
        $artefacts = new LDN_Artefacts($this->config);
        $page_level = $page_level !== null ? (string) $page_level : (string) $ctx->page_level;
        if (!$artefacts->site_has_wp_widget($ctx->site_id, 'price_calculator', $page_level)) {
            return '';
        }
        $presentation = $artefacts->wp_widget_presentation(
            $ctx->site_id,
            'price_calculator',
            $page_level
        );
        if ($presentation === null || $presentation === '' || $presentation === 'disabled') {
            return '';
        }

        $panel = $this->price_calculator_panel_from_manifest($ctx, $manifest);
        if ($panel === '') {
            return '';
        }

        $shape_label = $this->price_calculator_shape_label($manifest);
        $carat = isset($manifest['carat_weight']) ? (string) $manifest['carat_weight'] : '';

        $heading = ($carat !== '' && $shape_label !== '')
            ? sprintf(
                /* translators: 1: carat weight, 2: shape name */
                __('%1$s ct %2$s diamond price calculator', 'loupe-diamond-network'),
                $carat,
                $shape_label
            )
            : __('Diamond price calculator', 'loupe-diamond-network');

        // Intro lives on the shape-page shell, not inside the panel, so the
        // destination page and all-shapes hub can supply their own shorter lead
        // without inheriting "the median at the top of this page".
        return '<section class="ldn-section ldn-price-calculator"'
            . ' data-ldn-price-calculator="1">'
            . '<h2>' . esc_html($heading) . '</h2>'
            . $this->price_calculator_prose_html($this->price_calculator_intro($manifest))
            . $panel
            . '</section>';
    }

    /**
     * Calculator controls + result block (no outer section wrapper, no intro).
     *
     * @param LDN_Page_Context $ctx
     * @param array            $manifest
     * @return string
     */
    private function price_calculator_panel_from_manifest(LDN_Page_Context $ctx, array $manifest) {
        $manifest = $this->normalize_price_calculator_manifest($manifest);
        $default = $this->price_calculator_default_cell($manifest);
        if ($default === null) {
            return '';
        }

        $labels = $this->price_calculator_labels($ctx, $manifest);
        $payload = $manifest;
        $payload['labels'] = $labels;
        // Cut stops for the JS slider, same shape as colours/clarities.
        if (!empty($manifest['has_cut_dimension'])) {
            $cuts = array();
            foreach ($this->price_calculator_cut_options($manifest) as $option) {
                $cuts[] = array(
                    'key'   => (string) $option['value'],
                    'label' => (string) $option['label'],
                );
            }
            $payload['cuts'] = $cuts;
        }

        return '<div class="ldn-price-calculator" data-ldn-price-calculator="1">'
            . $this->price_calculator_controls_html($ctx, $manifest, $labels)
            . $this->price_calculator_result_html($ctx, $manifest, $default, $labels)
            . '<noscript><p class="ldn-price-calculator__noscript">'
            . esc_html__(
                'The figures above are for the most common specification at this weight. '
                . 'Choosing a different one needs JavaScript.',
                'loupe-diamond-network'
            )
            . '</p></noscript>'
            . $this->price_calculator_manifest_script($payload)
            . '</div>';
    }

    /**
     * Intro paragraph markup without depending on LDN_Renderer's content trait.
     *
     * @param string $text
     * @return string
     */
    private function price_calculator_prose_html($text) {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }
        return wp_kses_post(wpautop($text));
    }

    /**
     * The manifest's default cell with its figures, or null when unusable.
     *
     * @param array $manifest
     * @return array|null {color, clarity, cut_grade, sample_size, percentiles}
     */
    private function price_calculator_default_cell(array $manifest) {
        $manifest = $this->normalize_price_calculator_manifest($manifest);
        if (empty($manifest['cells']) || !is_array($manifest['cells'])) {
            return null;
        }
        $ref = isset($manifest['default_cell']) && is_array($manifest['default_cell'])
            ? $manifest['default_cell']
            : array();
        $colour = isset($ref['color']) ? (string) $ref['color'] : '';
        $clarity = isset($ref['clarity']) ? (string) $ref['clarity'] : '';
        $cut = isset($ref['cut_grade']) ? (string) $ref['cut_grade'] : '';
        if ($colour === '' || $clarity === '' || $cut === '') {
            return null;
        }
        if (!isset($manifest['cells'][$colour][$clarity][$cut])) {
            return null;
        }
        $cell = $manifest['cells'][$colour][$clarity][$cut];
        if (!is_array($cell) || empty($cell['percentiles']) || !is_array($cell['percentiles'])) {
            return null;
        }
        return array(
            'color'       => $colour,
            'clarity'     => $clarity,
            'cut_grade'   => $cut,
            'sample_size' => isset($cell['sample_size']) ? (int) $cell['sample_size'] : 0,
            'percentiles' => $cell['percentiles'],
        );
    }

    /**
     * Lead paragraph: what the module does, and how much data is behind it.
     *
     * @param array $manifest
     * @return string
     */
    private function price_calculator_intro(array $manifest) {
        $has_cut = !empty($manifest['has_cut_dimension']);
        $what = $has_cut
            ? __(
                'Choose the colour, clarity and cut you are considering and this shows '
                . 'what diamonds matching that exact specification are selling for. Add '
                . 'a price you have been quoted and it will tell you where that price '
                . 'sits against the same set of stones.',
                'loupe-diamond-network'
            )
            : __(
                'Choose the colour and clarity you are considering and this shows what '
                . 'diamonds matching that exact specification are selling for. Add a '
                . 'price you have been quoted and it will tell you where that price sits '
                . 'against the same set of stones.',
                'loupe-diamond-network'
            );

        $total = isset($manifest['total_sample_size']) ? (int) $manifest['total_sample_size'] : 0;
        if ($total > 0) {
            $what .= ' ' . sprintf(
                /* translators: %s: formatted count of diamonds */
                __(
                    'It compares like with like across %s diamonds, so a high price for '
                    . 'a modest specification cannot hide behind an average.',
                    'loupe-diamond-network'
                ),
                number_format($total)
            );
        }

        $what .= ' ' . __(
            'The median at the top of this page covers every colour and clarity grade. '
            . 'Use the selectors below to narrow that to the specification you are '
            . 'actually shopping for.',
            'loupe-diamond-network'
        );

        // Fancy-shape cut explanation lives on the disabled cut control, not here —
        // repeating it in the lead and under the slider would say the same thing twice.

        return $what;
    }

    /**
     * The selectors plus the optional quoted-price field.
     *
     * Two columns on a wide viewport (colour/clarity | cut/quote), stacking on
     * narrow screens. Cut is always present: interactive for round, greyed with
     * a reason for fancy shapes so the control does not silently vanish.
     *
     * @param array $manifest
     * @param array $labels
     * @return string
     */
    private function price_calculator_controls_html(LDN_Page_Context $ctx, array $manifest, array $labels) {
        $helps = $this->price_calculator_field_helps($ctx);
        $color_label = $this->price_calculator_color_label($ctx);

        $colour = $this->price_calculator_grade_slider_html(
            'colour',
            $color_label,
            $this->price_calculator_grade_options($manifest, 'colours'),
            isset($manifest['default_cell']['color'])
                ? (string) $manifest['default_cell']['color']
                : '',
            $helps['colour']
        );
        $clarity = $this->price_calculator_grade_slider_html(
            'clarity',
            __('Clarity', 'loupe-diamond-network'),
            $this->price_calculator_grade_options($manifest, 'clarities'),
            isset($manifest['default_cell']['clarity'])
                ? (string) $manifest['default_cell']['clarity']
                : '',
            $helps['clarity']
        );

        if (!empty($manifest['has_cut_dimension'])) {
            $cut = $this->price_calculator_grade_slider_html(
                'cut',
                __('Cut', 'loupe-diamond-network'),
                $this->price_calculator_cut_options($manifest),
                $this->price_calculator_pooled_key($manifest),
                $helps['cut']
            );
        } else {
            $cut = $this->price_calculator_disabled_cut_html($helps['cut']);
        }

        $price = '<div class="ldn-price-calculator__field ldn-price-calculator__field--quote">'
            . '<span class="ldn-price-calculator__field-label">'
            . '<span class="ldn-price-calculator__field-title">'
            . esc_html__('Quoted price', 'loupe-diamond-network')
            . ' <em>' . esc_html__('optional', 'loupe-diamond-network') . '</em></span>'
            . $this->price_calculator_field_help_html($helps['quote'])
            . '</span>'
            . '<input type="text" inputmode="numeric" autocomplete="off"'
            . ' data-ldn-price-calculator-quote="1"'
            . ' aria-label="' . esc_attr__('Quoted price', 'loupe-diamond-network') . '"'
            . ' placeholder="' . esc_attr($labels['currency'] . '0') . '">'
            . '<p class="ldn-price-calculator__quote-hint">'
            . esc_html__('See where your quote sits in this range.', 'loupe-diamond-network')
            . '</p>'
            . '</div>';

        return '<div class="ldn-price-calculator__controls">'
            . '<div class="ldn-price-calculator__col">' . $colour . $clarity . '</div>'
            . '<div class="ldn-price-calculator__col">' . $cut . $price . '</div>'
            . '</div>';
    }

    /**
     * Short field definitions for the ? affordance (AEO + beginner readers).
     *
     * @return array<string, string>
     */
    private function price_calculator_field_helps(LDN_Page_Context $ctx) {
        $us = strtolower((string) $ctx->country_code) === 'us';
        return array(
            'colour'  => $us
                ? __(
                    'How colorless the diamond looks. D is colorless; grades toward J show more tint.',
                    'loupe-diamond-network'
                )
                : __(
                    'How colourless the diamond looks. D is colourless; grades toward J show more tint.',
                    'loupe-diamond-network'
                ),
            'clarity' => __(
                'How few inclusions the diamond has. IF is cleanest; SI often still looks clean to the eye.',
                'loupe-diamond-network'
            ),
            'cut'     => __(
                'How well the diamond is proportioned. Better cut returns more brilliance. Graded for rounds.',
                'loupe-diamond-network'
            ),
            'quote'   => __(
                'Optional. Enter a price you have been quoted to see where it sits against live listings.',
                'loupe-diamond-network'
            ),
        );
    }

    /**
     * Visible color-grade label: US spelling on US pages.
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    private function price_calculator_color_label(LDN_Page_Context $ctx) {
        return strtolower((string) $ctx->country_code) === 'us'
            ? __('Color', 'loupe-diamond-network')
            : __('Colour', 'loupe-diamond-network');
    }

    /**
     * Inline ? control. Uses details/summary so it works with JavaScript off.
     *
     * @param string $help
     * @return string
     */
    private function price_calculator_field_help_html($help) {
        $help = trim((string) $help);
        if ($help === '') {
            return '';
        }
        return '<details class="ldn-field-help">'
            . '<summary class="ldn-field-help__summary">'
            . '<span class="ldn-field-help__mark" aria-hidden="true">?</span>'
            . '<span class="screen-reader-text">'
            . esc_html__('About this field', 'loupe-diamond-network')
            . '</span></summary>'
            . '<p class="ldn-field-help__tip">' . esc_html($help) . '</p>'
            . '</details>';
    }

    /**
     * Greyed cut control for fancy shapes — teaches why cut is absent.
     *
     * @param string $help
     * @return string
     */
    private function price_calculator_disabled_cut_html($help) {
        $reason = __(
            'Cut grading is not comparable across fancy shapes the way it is for round diamonds.',
            'loupe-diamond-network'
        );
        return '<div class="ldn-grade-slider ldn-price-calculator__field'
            . ' ldn-price-calculator__field--disabled" data-ldn-grade-slider="cut">'
            . '<span class="ldn-grade-slider__label">'
            . '<span class="ldn-price-calculator__field-title">'
            . esc_html__('Cut', 'loupe-diamond-network')
            . '</span>'
            . $this->price_calculator_field_help_html($help)
            . '</span>'
            . '<input type="range" class="ldn-grade-slider__input ldn-brand-range" min="0" max="1" value="0" disabled'
            . ' aria-disabled="true">'
            . '<p class="ldn-price-calculator__disabled-note">' . esc_html($reason) . '</p>'
            . '</div>';
    }

    /**
     * Point slider for an individual colour, clarity, or cut grade.
     *
     * @param string $name
     * @param string $label
     * @param array  $options [{value, label}]
     * @param string $selected
     * @param string $help
     * @return string
     */
    private function price_calculator_grade_slider_html($name, $label, array $options, $selected, $help = '') {
        if ($options === array()) {
            return '';
        }
        $selected_index = 0;
        $max = count($options) - 1;
        $stops = '';
        foreach ($options as $index => $option) {
            $value = (string) $option['value'];
            if ($value === (string) $selected) {
                $selected_index = $index;
            }
            $active = $value === (string) $selected ? ' ldn-grade-slider__stop--active' : '';
            $display = isset($option['short']) && $option['short'] !== ''
                ? (string) $option['short']
                : (string) $option['label'];
            // Match native range thumb geometry: value i of max m sits at i/m along
            // the track. Equal-width flex columns centre labels in cells instead,
            // so D-F looked left of the thumb and the last stop floated early.
            $pct = $max > 0 ? round(($index / $max) * 100, 4) : 0;
            $stops .= '<button type="button" class="ldn-grade-slider__stop' . $active . '"'
                . ' data-grade-value="' . esc_attr($value) . '"'
                . ' style="left:' . esc_attr((string) $pct) . '%"'
                . ' aria-label="' . esc_attr($option['label']) . '">'
                . esc_html($display) . '</button>';
        }
        return '<div class="ldn-grade-slider ldn-price-calculator__field"'
            . ' data-ldn-grade-slider="' . esc_attr($name) . '">'
            . '<span class="ldn-grade-slider__label">'
            . '<span class="ldn-price-calculator__field-title">' . esc_html($label) . '</span>'
            . $this->price_calculator_field_help_html($help)
            . '</span>'
            . '<input type="range" class="ldn-grade-slider__input ldn-brand-range" min="0" max="' . esc_attr((string) $max) . '"'
            . ' value="' . esc_attr((string) $selected_index) . '"'
            . ' data-ldn-price-calculator-input="' . esc_attr($name) . '">'
            . '<div class="ldn-grade-slider__stops">' . $stops . '</div>'
            . '</div>';
    }

    /**
     * @param string $name    control key read by the JavaScript
     * @param string $label
     * @param array  $options [{value, label}]
     * @param string $selected
     * @return string
     */
    private function price_calculator_select_html($name, $label, array $options, $selected) {
        if ($options === array()) {
            return '';
        }
        $opts = '';
        foreach ($options as $option) {
            $value = (string) $option['value'];
            $opts .= '<option value="' . esc_attr($value) . '"'
                . ($value === (string) $selected ? ' selected' : '') . '>'
                . esc_html($option['label']) . '</option>';
        }
        return '<label class="ldn-price-calculator__field">'
            . '<span>' . esc_html($label) . '</span>'
            . '<select data-ldn-price-calculator-input="' . esc_attr($name) . '">'
            . $opts . '</select></label>';
    }

    /**
     * Options for a colour or clarity slider, straight from the manifest so a
     * grade with no cells is never offered.
     *
     * @param array  $manifest
     * @param string $key colours | clarities
     * @return array
     */
    private function price_calculator_grade_options(array $manifest, $key) {
        $manifest = $this->normalize_price_calculator_manifest($manifest);
        $grades = isset($manifest[$key]) && is_array($manifest[$key]) ? $manifest[$key] : array();
        $options = array();
        foreach ($grades as $grade) {
            if (!is_array($grade) || empty($grade['key'])) {
                continue;
            }
            $label = isset($grade['label']) && $grade['label'] !== ''
                ? (string) $grade['label']
                : (string) $grade['key'];
            $options[] = array('value' => (string) $grade['key'], 'label' => $label);
        }
        return $options;
    }

    /**
     * Cut options for the point slider: pooled "Any" first, then grades worst→best.
     *
     * The manifest publishes cut_grades best-first (Super Ideal … Fair); the
     * slider reverses that so it reads the same direction as colour (J→D) and
     * clarity (I2→IF).
     *
     * @param array $manifest
     * @return array
     */
    private function price_calculator_cut_options(array $manifest) {
        $grades = isset($manifest['cut_grades']) && is_array($manifest['cut_grades'])
            ? $manifest['cut_grades']
            : array();
        if ($grades === array()) {
            return array();
        }
        $options = array(array(
            'value' => $this->price_calculator_pooled_key($manifest),
            'label' => __('Any', 'loupe-diamond-network'),
        ));
        foreach (array_reverse(array_values($grades)) as $grade) {
            $grade = (string) $grade;
            $options[] = array(
                'value' => $grade,
                'label' => $grade,
                // Short stop text so six cut grades fit a single slider row.
                'short' => $this->price_calculator_cut_stop_label($grade),
            );
        }
        return $options;
    }

    /**
     * Compact cut-stop label for the slider; full name stays in aria-label.
     *
     * @param string $grade
     * @return string
     */
    private function price_calculator_cut_stop_label($grade) {
        $short = array(
            'Very Good'   => __('VG', 'loupe-diamond-network'),
            'Excellent'   => __('Ex', 'loupe-diamond-network'),
            'Super Ideal' => __('Ideal', 'loupe-diamond-network'),
        );
        return isset($short[$grade]) ? $short[$grade] : $grade;
    }

    /**
     * The pooled sentinel, read from the manifest rather than assumed, so the
     * value stays a data decision.
     *
     * @param array $manifest
     * @return string
     */
    private function price_calculator_pooled_key(array $manifest) {
        return isset($manifest['pooled_cut_key']) && $manifest['pooled_cut_key'] !== ''
            ? (string) $manifest['pooled_cut_key']
            : 'ALL';
    }

    /**
     * The answer. Server-rendered for the default cell so the page answers
     * without JavaScript; the JS replaces the inner content in place.
     *
     * @param array $manifest
     * @param array $cell
     * @param array $labels
     * @return string
     */
    private function price_calculator_result_html(
        LDN_Page_Context $ctx,
        array $manifest,
        array $cell,
        array $labels
    ) {
        $currency = $labels['currency'];
        $percentiles = $cell['percentiles'];

        $typical = isset($percentiles['p50']) ? $percentiles['p50'] : null;
        $low = isset($percentiles['p10']) ? $percentiles['p10'] : null;
        $high = isset($percentiles['p90']) ? $percentiles['p90'] : null;

        $lines = array();
        $price_row = $this->price_calculator_price_row_html($typical, $low, $high, $currency, $labels);
        if ($price_row !== '') {
            $lines[] = $price_row;
        }
        $sample = $this->price_calculator_spec_sentence($ctx, $manifest, $cell);
        if ($sample !== '') {
            $lines[] = '<p class="ldn-price-calculator__sample">' . esc_html($sample) . '</p>';
        }

        if ($lines === array()) {
            return '';
        }

        return '<div class="ldn-price-calculator__result"'
            . ' data-ldn-price-calculator-result="1" aria-live="polite">'
            . implode('', $lines)
            . '</div>';
    }

    /**
     * p10 — p50 — p90 price row: wings smaller, median centred and large,
     * each column labelled so the three figures are not anonymous numbers.
     *
     * @param mixed  $typical
     * @param mixed  $low
     * @param mixed  $high
     * @param string $currency
     * @param array  $labels
     * @return string
     */
    private function price_calculator_price_row_html($typical, $low, $high, $currency, array $labels = array()) {
        if ($typical === null) {
            return '';
        }
        $label_low = isset($labels['boundLow']) ? (string) $labels['boundLow'] : __('Lower end', 'loupe-diamond-network');
        $label_mid = isset($labels['boundTypical']) ? (string) $labels['boundTypical'] : __('Typical', 'loupe-diamond-network');
        $label_high = isset($labels['boundHigh']) ? (string) $labels['boundHigh'] : __('Higher end', 'loupe-diamond-network');

        $centre = '<span class="ldn-price-calculator__headline">'
            . '<span class="ldn-price-calculator__bound-label">' . esc_html($label_mid) . '</span>'
            . '<span class="ldn-price-calculator__headline-value">'
            . esc_html($this->price_calculator_money($typical, $currency))
            . '</span></span>';
        $left = '';
        $right = '';
        if ($low !== null) {
            $left = '<span class="ldn-price-calculator__bound ldn-price-calculator__bound--low">'
                . '<span class="ldn-price-calculator__bound-label">' . esc_html($label_low) . '</span>'
                . '<span class="ldn-price-calculator__bound-value">'
                . esc_html($this->price_calculator_money($low, $currency))
                . '</span></span>';
        }
        if ($high !== null) {
            $right = '<span class="ldn-price-calculator__bound ldn-price-calculator__bound--high">'
                . '<span class="ldn-price-calculator__bound-label">' . esc_html($label_high) . '</span>'
                . '<span class="ldn-price-calculator__bound-value">'
                . esc_html($this->price_calculator_money($high, $currency))
                . '</span></span>';
        }
        return '<div class="ldn-price-calculator__price-row">' . $left . $centre . $right . '</div>';
    }

    /**
     * "Based on 412 G-H, VS diamonds of any cut."
     *
     * The sample size is part of the claim, not a diagnostic: a percentile the
     * reader is asked to trust always says how many stones it rests on.
     *
     * @param array $manifest
     * @param array $cell
     * @return string
     */
    private function price_calculator_spec_sentence(LDN_Page_Context $ctx, array $manifest, array $cell) {
        $n = (int) $cell['sample_size'];
        if ($n < 1) {
            return '';
        }
        $spec = $this->price_calculator_spec_label($ctx, $manifest, $cell);
        if ($spec === '') {
            return sprintf(
                /* translators: %s: formatted count of diamonds */
                _n(
                    'Based on %s diamond.',
                    'Based on %s diamonds.',
                    $n,
                    'loupe-diamond-network'
                ),
                number_format($n)
            );
        }
        return sprintf(
            /* translators: 1: formatted count of diamonds, 2: specification, e.g. "G-H, VS, any cut" */
            _n(
                'Based on %1$s diamond at %2$s.',
                'Based on %1$s diamonds at %2$s.',
                $n,
                'loupe-diamond-network'
            ),
            number_format($n),
            $spec
        );
    }

    /**
     * Reader-facing specification, e.g. "G-H colour, VS clarity, any cut".
     *
     * @param array $manifest
     * @param array $cell
     * @return string
     */
    private function price_calculator_spec_label(LDN_Page_Context $ctx, array $manifest, array $cell) {
        $parts = array();
        $colour = $this->price_calculator_grade_label($manifest, 'colours', $cell['color']);
        if ($colour !== '') {
            $template = strtolower((string) $ctx->country_code) === 'us'
                ? __('%s color', 'loupe-diamond-network')
                : __('%s colour', 'loupe-diamond-network');
            $parts[] = sprintf($template, $colour);
        }
        $clarity = $this->price_calculator_grade_label($manifest, 'clarities', $cell['clarity']);
        if ($clarity !== '') {
            $parts[] = sprintf(
                /* translators: %s: clarity group, e.g. VS */
                __('%s clarity', 'loupe-diamond-network'),
                $clarity
            );
        }
        if (!empty($manifest['has_cut_dimension'])) {
            $parts[] = $cell['cut_grade'] === $this->price_calculator_pooled_key($manifest)
                ? __('any cut', 'loupe-diamond-network')
                : sprintf(
                    /* translators: %s: cut grade, e.g. Excellent */
                    __('%s cut', 'loupe-diamond-network'),
                    $cell['cut_grade']
                );
        }
        return implode(', ', $parts);
    }

    /**
     * @param array  $manifest
     * @param string $key colours | clarities
     * @param string $grade_key
     * @return string
     */
    private function price_calculator_grade_label(array $manifest, $key, $grade_key) {
        $grades = isset($manifest[$key]) && is_array($manifest[$key]) ? $manifest[$key] : array();
        foreach ($grades as $grade) {
            if (is_array($grade) && isset($grade['key']) && (string) $grade['key'] === (string) $grade_key) {
                return isset($grade['label']) ? (string) $grade['label'] : (string) $grade_key;
            }
        }
        return (string) $grade_key;
    }

    /**
     * Whole-unit price. Percentiles carry cents from the USD conversion, which
     * is noise at these magnitudes.
     *
     * @param mixed  $value
     * @param string $currency symbol
     * @return string
     */
    private function price_calculator_money($value, $currency) {
        if (!is_numeric($value)) {
            return '';
        }
        return $currency . number_format((float) $value, 0);
    }

    /**
     * Shape name for the heading, from the manifest rather than page context.
     *
     * @param array $manifest
     * @return string
     */
    private function price_calculator_shape_label(array $manifest) {
        $shape = isset($manifest['shape']) ? (string) $manifest['shape'] : '';
        if ($shape === '') {
            return '';
        }
        return ucwords(str_replace('-', ' ', $shape));
    }

    /**
     * Every string the JavaScript renders, so translation stays in PHP and the
     * JS holds no English of its own.
     *
     * @param array $manifest
     * @return array<string, mixed>
     */
    private function price_calculator_labels(LDN_Page_Context $ctx, array $manifest) {
        $currency = $this->currency_symbol(
            isset($manifest['currency']) ? $manifest['currency'] : 'USD'
        );
        $us = strtolower((string) $ctx->country_code) === 'us';
        $colour_template = $us
            ? __('%s color', 'loupe-diamond-network')
            : __('%s colour', 'loupe-diamond-network');
        $no_cell = $us
            ? __(
                'We do not have enough diamonds at that exact specification to give a '
                . 'reliable figure. Try a different color or clarity.',
                'loupe-diamond-network'
            )
            : __(
                'We do not have enough diamonds at that exact specification to give a '
                . 'reliable figure. Try a different colour or clarity.',
                'loupe-diamond-network'
            );

        $labels = array(
            'currency'      => $currency,
            'price'         => '%price%',
            'range'         => '%low% – %high%',
            'colour'        => $colour_template,
            'clarity'       => __('%s clarity', 'loupe-diamond-network'),
            'boundLow'      => __('Lower end', 'loupe-diamond-network'),
            'boundTypical'  => __('Typical', 'loupe-diamond-network'),
            'boundHigh'     => __('Higher end', 'loupe-diamond-network'),
            // %count% / %spec% tokens — JS has no gettext plural forms of its own.
            'basedOnOne'    => __('Based on %count% diamond at %spec%.', 'loupe-diamond-network'),
            'basedOnMany'   => __('Based on %count% diamonds at %spec%.', 'loupe-diamond-network'),
            'basedOnOneBare'  => __('Based on %count% diamond.', 'loupe-diamond-network'),
            'basedOnManyBare' => __('Based on %count% diamonds.', 'loupe-diamond-network'),
            'noCell'        => $no_cell,
            'colorWord'     => strtolower($this->price_calculator_color_label($ctx)),
            'driverIncrease' => __(
                'Choosing %alt% over %current% %dimension% typically adds about %pct%% to the median price.',
                'loupe-diamond-network'
            ),
            'driverDecrease' => __(
                'Choosing %alt% over %current% %dimension% typically lowers the median by about %pct%%.',
                'loupe-diamond-network'
            ),
            // Position-aware quote commentary (hybrid percentile bands).
            'quoteAnalysis' => $this->price_calculator_quote_analysis_labels(),
        );

        // A page with no cut dimension never renders a cut phrase, so shipping
        // one would put a string in the payload that nothing can use.
        if (!empty($manifest['has_cut_dimension'])) {
            $labels['cut'] = __('%s cut', 'loupe-diamond-network');
            $labels['anyCut'] = __('any cut', 'loupe-diamond-network');
        }

        return $labels;
    }

    /**
     * Quote-position commentary keyed by analysis slot (below_p10, band_00–09, above_p90).
     *
     * Each line states where the quote sits and reminds the reader that price is
     * one input — cut, certification, and seller terms matter too.
     *
     * @return array<string, string>
     */
    private function price_calculator_quote_analysis_labels() {
        return array(
            'below_p10' => __(
                'This quote is well below what similar diamonds usually sell for. '
                . 'That can be a great deal — but verify the certificate, cut quality, '
                . 'and return policy before price is the only thing you weigh.',
                'loupe-diamond-network'
            ),
            'p10_p25_0' => __(
                'This quote sits just above the lowest prices we see for this specification. '
                . 'It looks competitive — compare the grading report and how the stone faces up.',
                'loupe-diamond-network'
            ),
            'p10_p25_1' => __(
                'This quote is in the lower quarter of the market for this specification. '
                . 'Savings are plausible; still weigh cut, eye-cleanliness, and seller terms.',
                'loupe-diamond-network'
            ),
            'p10_p25_2' => __(
                'This quote is below what most comparable diamonds cost, but not an outlier. '
                . 'A fair price is only part of the decision — check certification and service.',
                'loupe-diamond-network'
            ),
            'p10_p25_3' => __(
                'This quote is approaching the middle of the range for this specification. '
                . 'You are not overpaying on price alone; prioritise cut and seller reputation.',
                'loupe-diamond-network'
            ),
            'p25_p50_0' => __(
                'This quote is a little under the typical price for this specification. '
                . 'That is a reasonable place to be — use any savings on cut or a seller you trust.',
                'loupe-diamond-network'
            ),
            'p25_p50_1' => __(
                'This quote is slightly below the middle of the market for this spec. '
                . 'Price looks fair; the rest is whether this stone\'s proportions suit you.',
                'loupe-diamond-network'
            ),
            'p25_p50_2' => __(
                'This quote is close to the typical price for this specification. '
                . 'You are paying roughly what comparable stones sell for — focus on cut and cert.',
                'loupe-diamond-network'
            ),
            'p25_p50_3' => __(
                'This quote is just under the median for this specification. '
                . 'Not a bargain, not a premium — make sure the stone earns the spend on merit.',
                'loupe-diamond-network'
            ),
            'p50_p75_0' => __(
                'This quote is near the middle of what comparable diamonds sell for. '
                . 'Price looks in line with the market; cut and seller terms should decide it.',
                'loupe-diamond-network'
            ),
            'p50_p75_1' => __(
                'This quote is a little above the typical range for this specification. '
                . 'You may be paying a modest premium — confirm cut quality justifies it.',
                'loupe-diamond-network'
            ),
            'p50_p75_2' => __(
                'This quote is above what most comparable diamonds cost. '
                . 'Higher price is not automatically better; check the certificate and policies.',
                'loupe-diamond-network'
            ),
            'p50_p75_3' => __(
                'This quote sits toward the upper half of the market for this specification. '
                . 'Unless something specific justifies it, it is worth shopping alternatives.',
                'loupe-diamond-network'
            ),
            'p75_p90_0' => __(
                'This quote is above the typical range for this specification. '
                . 'Make sure cut, certification, or seller reputation earns the extra spend.',
                'loupe-diamond-network'
            ),
            'p75_p90_1' => __(
                'This quote sits toward the high end of the market for this specification. '
                . 'Compare other options unless a brand or terms you need explains the premium.',
                'loupe-diamond-network'
            ),
            'p75_p90_2' => __(
                'This quote is near the top of what similar diamonds sell for. '
                . 'Price alone suggests you could do better — weigh cut and seller trust carefully.',
                'loupe-diamond-network'
            ),
            'p75_p90_3' => __(
                'This quote is at the high end of the range for this specification. '
                . 'Unless there is a clear reason to pay more, compare other stones first.',
                'loupe-diamond-network'
            ),
            'above_p90' => __(
                'This quote is higher than almost every comparable diamond we track. '
                . 'Unless there is a clear reason — a specific cut grade, a trusted '
                . 'seller, or terms you need — compare other options before you commit.',
                'loupe-diamond-network'
            ),
        );
    }

    /**
     * Three-step explainer for the standalone calculator page.
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    private function calculator_how_it_works_html(LDN_Page_Context $ctx) {
        $color_word = strtolower($this->price_calculator_color_label($ctx));
        $steps = array(
            sprintf(
                /* translators: %s: localized color/colour word */
                __(
                    'Choose the shape, carat, type, and the %s, clarity, and cut grades '
                    . 'you are shopping for.',
                    'loupe-diamond-network'
                ),
                $color_word
            ),
            __(
                'We show the lower end, typical, and higher end prices from live listings '
                . 'that match that exact specification.',
                'loupe-diamond-network'
            ),
            __(
                'Add a quoted price to see where it sits on the market range for the same spec.',
                'loupe-diamond-network'
            ),
        );
        $out = '<ol class="ldn-calculator-how__steps">';
        foreach ($steps as $step) {
            $out .= '<li>' . esc_html($step) . '</li>';
        }
        $out .= '</ol>';
        return '<div class="ldn-calculator-how"><h2 class="ldn-calculator-how__title">'
            . esc_html__('How it works', 'loupe-diamond-network') . '</h2>' . $out . '</div>';
    }

    /**
     * Live price-driver deltas for the selected specification (initial server render).
     *
     * @param LDN_Page_Context $ctx
     * @param array            $manifest
     * @return string
     */
    private function calculator_what_drives_price_html(LDN_Page_Context $ctx, array $manifest) {
        $default = $this->price_calculator_default_cell($manifest);
        if ($default === null) {
            return '';
        }
        $lines = $this->price_calculator_price_driver_lines(
            $manifest,
            $ctx,
            $default['color'],
            $default['clarity'],
            $default['cut_grade']
        );
        if ($lines === array()) {
            return '';
        }
        $list = '<ul class="ldn-calculator-drivers__list" data-ldn-calculator-drivers-list="1">';
        foreach ($lines as $line) {
            $list .= '<li>' . esc_html($line) . '</li>';
        }
        $list .= '</ul>';
        $intro = __(
            'Small changes to color, clarity, or cut can move the median price more than '
            . 'many shoppers expect. At your current selection:',
            'loupe-diamond-network'
        );
        $note = __(
            'These percentages update when you change the selectors above.',
            'loupe-diamond-network'
        );
        return '<div class="ldn-calculator-drivers" data-ldn-calculator-drivers="1">'
            . '<h2 class="ldn-calculator-drivers__title">'
            . esc_html__('What drives the price?', 'loupe-diamond-network')
            . '</h2>'
            . '<p class="ldn-calculator-drivers__intro">' . esc_html($intro) . '</p>'
            . $list
            . '<p class="ldn-calculator-drivers__note">' . esc_html($note) . '</p>'
            . '</div>';
    }

    /**
     * Phase 8 consumer guidance slot (recommended diamonds). Renders an empty shell
     * until the listings module hydrates it; gated by consumer_advisory_enabled()
     * and the calculator-level individual_listings widget entitlement.
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    private function calculator_consumer_guidance_html(LDN_Page_Context $ctx) {
        if (!$this->config->consumer_advisory_enabled($ctx->site_id, $ctx->country_code)) {
            return '';
        }
        $artefacts = new LDN_Artefacts($this->config);
        if (!$artefacts->site_has_wp_widget($ctx->site_id, 'individual_listings', 'calculator')) {
            return '';
        }
        return '<section class="ldn-calculator-consumer-guidance"'
            . ' data-ldn-calculator-consumer-guidance="1"'
            . ' data-ldn-calculator-country="' . esc_attr($ctx->country_code) . '"'
            . ' aria-label="' . esc_attr__('Recommended diamonds', 'loupe-diamond-network') . '">'
            . '</section>';
    }

    /**
     * Median price for one manifest cell, or null when missing.
     *
     * @param array  $manifest
     * @param string $colour
     * @param string $clarity
     * @param string $cut
     * @return float|null
     */
    private function price_calculator_cell_median(array $manifest, $colour, $clarity, $cut) {
        $manifest = $this->normalize_price_calculator_manifest($manifest);
        if (!isset($manifest['cells'][$colour][$clarity][$cut]['percentiles']['p50'])) {
            return null;
        }
        $value = $manifest['cells'][$colour][$clarity][$cut]['percentiles']['p50'];
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param array<int, array{value: string, label: string}> $options
     * @param string                                          $value
     * @return int|null
     */
    private function price_calculator_option_index(array $options, $value) {
        foreach ($options as $index => $option) {
            if (isset($option['value']) && (string) $option['value'] === (string) $value) {
                return (int) $index;
            }
        }
        return null;
    }

    /**
     * Reader-facing lines comparing one grade step up on each dimension.
     *
     * @param array            $manifest
     * @param LDN_Page_Context $ctx
     * @param string           $colour
     * @param string           $clarity
     * @param string           $cut
     * @return string[]
     */
    private function price_calculator_price_driver_lines(
        array $manifest,
        LDN_Page_Context $ctx,
        $colour,
        $clarity,
        $cut
    ) {
        $manifest = $this->normalize_price_calculator_manifest($manifest);
        $base = $this->price_calculator_cell_median($manifest, $colour, $clarity, $cut);
        if ($base === null || $base <= 0) {
            return array();
        }

        $lines = array();
        $color_word = strtolower($this->price_calculator_color_label($ctx));
        $colours = $this->price_calculator_grade_options($manifest, 'colours');
        $clarities = $this->price_calculator_grade_options($manifest, 'clarities');
        $color_idx = $this->price_calculator_option_index($colours, $colour);
        $clarity_idx = $this->price_calculator_option_index($clarities, $clarity);

        if ($color_idx !== null && $color_idx > 0) {
            $better = $colours[$color_idx - 1];
            $line = $this->price_calculator_driver_delta_line(
                $base,
                $this->price_calculator_cell_median($manifest, $better['value'], $clarity, $cut),
                $colours[$color_idx]['label'],
                $better['label'],
                $color_word
            );
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        if ($clarity_idx !== null && $clarity_idx > 0) {
            $better = $clarities[$clarity_idx - 1];
            $line = $this->price_calculator_driver_delta_line(
                $base,
                $this->price_calculator_cell_median($manifest, $colour, $better['value'], $cut),
                $clarities[$clarity_idx]['label'],
                $better['label'],
                __('clarity', 'loupe-diamond-network')
            );
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        if (!empty($manifest['has_cut_dimension'])) {
            $cuts = $this->price_calculator_cut_options($manifest);
            $cut_idx = $this->price_calculator_option_index($cuts, $cut);
            $pooled = $this->price_calculator_pooled_key($manifest);
            if ($cut_idx !== null && $cut_idx > 0 && (string) $cut !== $pooled) {
                $better = $cuts[$cut_idx - 1];
                if ((string) $better['value'] === $pooled) {
                    $better = $cuts[$cut_idx - 2] ?? null;
                }
                if (is_array($better)) {
                    $line = $this->price_calculator_driver_delta_line(
                        $base,
                        $this->price_calculator_cell_median(
                            $manifest,
                            $colour,
                            $clarity,
                            $better['value']
                        ),
                        $cuts[$cut_idx]['label'],
                        $better['label'],
                        __('cut', 'loupe-diamond-network')
                    );
                    if ($line !== null) {
                        $lines[] = $line;
                    }
                }
            } elseif ($cut_idx === 0 || (string) $cut === $pooled) {
                $next = isset($cuts[1]) ? $cuts[1] : null;
                if (is_array($next)) {
                    $line = $this->price_calculator_driver_delta_line(
                        $base,
                        $this->price_calculator_cell_median(
                            $manifest,
                            $colour,
                            $clarity,
                            $next['value']
                        ),
                        __('Any', 'loupe-diamond-network'),
                        $next['label'],
                        __('cut', 'loupe-diamond-network')
                    );
                    if ($line !== null) {
                        $lines[] = $line;
                    }
                }
            }
        }

        return $lines;
    }

    /**
     * @param float       $base
     * @param float|null  $alt
     * @param string      $current_label
     * @param string      $alt_label
     * @param string      $dimension_word
     * @return string|null
     */
    private function price_calculator_driver_delta_line(
        $base,
        $alt,
        $current_label,
        $alt_label,
        $dimension_word
    ) {
        if ($alt === null || $alt <= 0) {
            return null;
        }
        $pct = (int) round((($alt - $base) / $base) * 100);
        if ($pct === 0) {
            return null;
        }
        if ($pct > 0) {
            return sprintf(
                /* translators: 1: better grade label, 2: current grade label, 3: dimension (color/clarity/cut), 4: positive percent */
                __(
                    'Choosing %1$s over %2$s %3$s typically adds about %4$d%% to the median price.',
                    'loupe-diamond-network'
                ),
                $alt_label,
                $current_label,
                $dimension_word,
                abs($pct)
            );
        }
        return sprintf(
            /* translators: 1: better grade label, 2: current grade label, 3: dimension (color/clarity/cut), 4: positive percent */
            __(
                'Choosing %1$s over %2$s %3$s typically lowers the median by about %4$d%%.',
                'loupe-diamond-network'
            ),
            $alt_label,
            $current_label,
            $dimension_word,
            abs($pct)
        );
    }

    /**
     * Embed the manifest for the JavaScript. One object per page.
     *
     * @param array $payload
     * @return string
     */
    private function price_calculator_manifest_script(array $payload) {
        $flags = defined('JSON_UNESCAPED_SLASHES')
            ? (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : 0;
        $json = function_exists('wp_json_encode')
            ? wp_json_encode($payload, $flags)
            : json_encode($payload, $flags);
        if (!is_string($json) || $json === '') {
            return '';
        }
        return '<script type="application/json" class="ldn-price-calculator-manifest">'
            . str_replace('</', '<\/', $json) . '</script>';
    }

    /**
     * Standalone calculator destination page (all selectors free).
     *
     * @param LDN_Page_Context $ctx
     * @param array            $manifest Initial manifest (server-rendered default).
     * @return string
     */
    public function calculator_destination_html(LDN_Page_Context $ctx, array $manifest) {
        $artefacts = new LDN_Artefacts($this->config);
        if (!$artefacts->site_has_wp_widget($ctx->site_id, 'price_calculator', 'calculator')) {
            return '';
        }

        $panel = $this->price_calculator_panel_from_manifest($ctx, $manifest);
        if ($panel === '') {
            $panel = '<p class="ldn-price-calculator__unavailable">'
                . esc_html__(
                    'Calculator data is not available for this specification yet. '
                    . 'Try a different carat or shape.',
                    'loupe-diamond-network'
                )
                . '</p>';
        }

        return '<section class="ldn-section ldn-calculator-destination"'
            . ' data-ldn-calculator-destination="1"'
            . ' data-ldn-calculator-country="' . esc_attr($ctx->country_code) . '">'
            . '<div class="ldn-calculator-destination__pickers">'
            . $this->price_calculator_type_toggle_html($manifest)
            . $this->price_calculator_carat_picker_html($manifest)
            . '</div>'
            . $this->price_calculator_shape_picker_html($manifest)
            . '<div class="ldn-calculator-destination__tool" data-ldn-calculator-tool="1">'
            . $panel
            . '</div>'
            . '</section>';
    }

    /**
     * Floor / ceiling of the calculator carat grid (shared/config/carat_bands.py).
     * Kept as PHP constants so the plugin zip does not depend on Python at runtime.
     */
    private static $CALC_CARAT_MIN = 0.3;
    private static $CALC_CARAT_MAX = 12.0;

    /**
     * Natural / lab-grown as a segmented control (both options always visible).
     *
     * @param array $manifest
     * @return string
     */
    private function price_calculator_type_toggle_html(array $manifest) {
        $selected = isset($manifest['diamond_type'])
            ? strtolower(str_replace('_', '-', (string) $manifest['diamond_type']))
            : 'natural';
        if ($selected !== 'lab-grown' && $selected !== 'lab') {
            $selected = 'natural';
        } else {
            $selected = 'lab-grown';
        }

        $options = array(
            array('value' => 'natural', 'label' => __('Natural', 'loupe-diamond-network')),
            array('value' => 'lab-grown', 'label' => __('Lab-grown', 'loupe-diamond-network')),
        );
        $buttons = '';
        foreach ($options as $option) {
            $checked = $option['value'] === $selected ? ' checked' : '';
            $buttons .= '<label class="ldn-seg-toggle__option">'
                . '<input type="radio" name="ldn-calculator-type" value="'
                . esc_attr($option['value']) . '"' . $checked
                . ' data-ldn-calculator-type="1">'
                . '<span class="ldn-seg-toggle__label">' . esc_html($option['label']) . '</span>'
                . '</label>';
        }

        return '<fieldset class="ldn-seg-toggle">'
            . '<legend class="ldn-seg-toggle__legend">'
            . esc_html__('Diamond type', 'loupe-diamond-network')
            . '</legend>'
            . '<div class="ldn-seg-toggle__options">' . $buttons . '</div>'
            . '</fieldset>';
    }

    /**
     * Carat as a range slider paired with a numeric input (explore + precision).
     *
     * @param array $manifest
     * @return string
     */
    private function price_calculator_carat_picker_html(array $manifest) {
        $min = self::$CALC_CARAT_MIN;
        $max = self::$CALC_CARAT_MAX;
        $value = isset($manifest['carat_weight']) ? (float) $manifest['carat_weight'] : 1.0;
        if ($value < $min || $value > $max || !is_finite($value)) {
            $value = 1.0;
        }
        $display = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
        if ($display === '') {
            $display = '1';
        }

        return '<div class="ldn-carat-picker" data-ldn-carat-picker="1">'
            . '<span class="ldn-carat-picker__label">'
            . esc_html__('Carat weight', 'loupe-diamond-network')
            . '</span>'
            . '<div class="ldn-carat-picker__controls">'
            . '<input type="range" class="ldn-carat-picker__slider ldn-brand-range" min="' . esc_attr((string) $min) . '"'
            . ' max="' . esc_attr((string) $max) . '" step="0.1" value="' . esc_attr((string) $value) . '"'
            . ' data-ldn-calculator-carat-slider="1" aria-label="'
            . esc_attr__('Carat weight', 'loupe-diamond-network') . '">'
            . '<input type="number" class="ldn-carat-picker__number" min="' . esc_attr((string) $min) . '"'
            . ' max="' . esc_attr((string) $max) . '" step="0.01" value="' . esc_attr($display) . '"'
            . ' data-ldn-calculator-carat="1" aria-label="'
            . esc_attr__('Carat weight', 'loupe-diamond-network') . '">'
            . '</div>'
            . '</div>';
    }

    /**
     * Accept both individual-grade manifests (C5.9 post-migration 017) and the
     * legacy grouped manifest still on S3 until the pipeline re-publishes.
     *
     * @param array $manifest
     * @return array
     */
    private function normalize_price_calculator_manifest(array $manifest) {
        if (empty($manifest['colours']) && !empty($manifest['colour_groups']) && is_array($manifest['colour_groups'])) {
            $manifest['colours'] = $this->price_calculator_legacy_grade_list($manifest['colour_groups']);
        }
        if (empty($manifest['clarities']) && !empty($manifest['clarity_groups']) && is_array($manifest['clarity_groups'])) {
            $manifest['clarities'] = $this->price_calculator_legacy_grade_list($manifest['clarity_groups']);
        }
        if (!empty($manifest['default_cell']) && is_array($manifest['default_cell'])) {
            if (empty($manifest['default_cell']['color']) && !empty($manifest['default_cell']['colour_group'])) {
                $manifest['default_cell']['color'] = (string) $manifest['default_cell']['colour_group'];
            }
            if (empty($manifest['default_cell']['clarity']) && !empty($manifest['default_cell']['clarity_group'])) {
                $manifest['default_cell']['clarity'] = (string) $manifest['default_cell']['clarity_group'];
            }
        }
        return $manifest;
    }

    /**
     * @param array $groups
     * @return array<int, array{key:string,label:string}>
     */
    private function price_calculator_legacy_grade_list(array $groups) {
        $grades = array();
        foreach ($groups as $group) {
            if (!is_array($group) || empty($group['key'])) {
                continue;
            }
            $grades[] = array(
                'key'   => (string) $group['key'],
                'label' => isset($group['label']) && $group['label'] !== ''
                    ? (string) $group['label']
                    : (string) $group['key'],
            );
        }
        return $grades;
    }

    /**
     * Shapes offered on the destination calculator page.
     *
     * @return array<int, array{slug:string,label:string}>
     */
    /**
     * Shape chooser as a row of radio buttons carrying the faceted outline.
     *
     * Shape is the axis a reader recognises by sight, so it gets icons rather
     * than a dropdown. Radios (not buttons) so the row is keyboard-navigable and
     * announces as a single group without any JS.
     *
     * Icons are painted as CSS masks over `currentColor`, which is why the file
     * is referenced through a custom property rather than an `<img>`: the outline
     * has to take the site's brand colour when selected.
     *
     * @param array $manifest Used only to pre-select the server-rendered shape.
     * @return string
     */
    private function price_calculator_shape_picker_html(array $manifest) {
        $selected = isset($manifest['shape']) ? strtolower(trim((string) $manifest['shape'])) : '';
        $icon_base = defined('LDN_PLUGIN_URL') ? LDN_PLUGIN_URL . 'assets/img/shapes/' : '';
        $icon_ver = defined('LDN_VERSION') ? (string) LDN_VERSION : '';
        $shapes = $this->price_calculator_destination_shapes();

        if ($selected === '') {
            $selected = $shapes[0]['slug'];
        }

        $options = '';
        foreach ($shapes as $shape) {
            $slug = (string) $shape['slug'];
            $icon_url = $icon_base . $slug . '.svg';
            if ($icon_ver !== '') {
                $icon_url .= '?ver=' . rawurlencode($icon_ver);
            }
            $icon = $icon_base !== ''
                ? '<span class="ldn-shape-picker__icon" aria-hidden="true"'
                    . ' style="--ldn-shape-icon:url(' . esc_url($icon_url) . ')"></span>'
                : '';
            $options .= '<label class="ldn-shape-picker__option">'
                . '<input type="radio" name="ldn-calculator-shape" value="' . esc_attr($slug) . '"'
                . ($slug === $selected ? ' checked' : '')
                . ' data-ldn-calculator-shape="1">'
                . $icon
                . '<span class="ldn-shape-picker__label">' . esc_html($shape['label']) . '</span>'
                . '</label>';
        }

        return '<fieldset class="ldn-shape-picker">'
            . '<legend class="ldn-shape-picker__legend">'
            . esc_html__('Shape', 'loupe-diamond-network')
            . '</legend>'
            . '<div class="ldn-shape-picker__options">' . $options . '</div>'
            . '</fieldset>';
    }

    private function price_calculator_destination_shapes() {
        return array(
            array('slug' => 'round', 'label' => __('Round', 'loupe-diamond-network')),
            array('slug' => 'princess', 'label' => __('Princess', 'loupe-diamond-network')),
            array('slug' => 'oval', 'label' => __('Oval', 'loupe-diamond-network')),
            array('slug' => 'cushion', 'label' => __('Cushion', 'loupe-diamond-network')),
            array('slug' => 'emerald', 'label' => __('Emerald', 'loupe-diamond-network')),
            array('slug' => 'asscher', 'label' => __('Asscher', 'loupe-diamond-network')),
            array('slug' => 'radiant', 'label' => __('Radiant', 'loupe-diamond-network')),
            array('slug' => 'pear', 'label' => __('Pear', 'loupe-diamond-network')),
            array('slug' => 'marquise', 'label' => __('Marquise', 'loupe-diamond-network')),
            array('slug' => 'heart', 'label' => __('Heart', 'loupe-diamond-network')),
        );
    }
}
