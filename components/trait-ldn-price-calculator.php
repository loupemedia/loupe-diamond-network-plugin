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

        return '<section class="ldn-section ldn-price-calculator"'
            . ' data-ldn-price-calculator="1">'
            . '<h2>' . esc_html($heading) . '</h2>'
            . $panel
            . '</section>';
    }

    /**
     * Calculator controls + result block (no outer section wrapper).
     *
     * @param LDN_Page_Context $ctx
     * @param array            $manifest
     * @return string
     */
    private function price_calculator_panel_from_manifest(LDN_Page_Context $ctx, array $manifest) {
        $default = $this->price_calculator_default_cell($manifest);
        if ($default === null) {
            return '';
        }

        $labels = $this->price_calculator_labels($manifest);
        $payload = $manifest;
        $payload['labels'] = $labels;

        return '<div class="ldn-price-calculator" data-ldn-price-calculator="1">'
            . $this->format_prose_html($this->price_calculator_intro($manifest))
            . $this->price_calculator_controls_html($manifest, $labels)
            . $this->price_calculator_result_html($manifest, $default, $labels)
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
     * The manifest's default cell with its figures, or null when unusable.
     *
     * @param array $manifest
     * @return array|null {colour_group, clarity_group, cut_grade, sample_size, percentiles}
     */
    private function price_calculator_default_cell(array $manifest) {
        if (empty($manifest['cells']) || !is_array($manifest['cells'])) {
            return null;
        }
        $ref = isset($manifest['default_cell']) && is_array($manifest['default_cell'])
            ? $manifest['default_cell']
            : array();
        $colour = isset($ref['colour_group']) ? (string) $ref['colour_group'] : '';
        $clarity = isset($ref['clarity_group']) ? (string) $ref['clarity_group'] : '';
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
            'colour_group'  => $colour,
            'clarity_group' => $clarity,
            'cut_grade'     => $cut,
            'sample_size'   => isset($cell['sample_size']) ? (int) $cell['sample_size'] : 0,
            'percentiles'   => $cell['percentiles'],
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

        if (!$has_cut) {
            $what .= ' ' . __(
                'There is no cut option here because cut grading is not comparable '
                . 'across fancy shapes the way it is for round diamonds.',
                'loupe-diamond-network'
            );
        }

        return $what;
    }

    /**
     * The selectors plus the optional quoted-price field.
     *
     * @param array $manifest
     * @param array $labels
     * @return string
     */
    private function price_calculator_controls_html(array $manifest, array $labels) {
        $colour = $this->price_calculator_select_html(
            'colour',
            __('Colour', 'loupe-diamond-network'),
            $this->price_calculator_group_options($manifest, 'colour_groups'),
            isset($manifest['default_cell']['colour_group'])
                ? (string) $manifest['default_cell']['colour_group']
                : ''
        );
        $clarity = $this->price_calculator_select_html(
            'clarity',
            __('Clarity', 'loupe-diamond-network'),
            $this->price_calculator_group_options($manifest, 'clarity_groups'),
            isset($manifest['default_cell']['clarity_group'])
                ? (string) $manifest['default_cell']['clarity_group']
                : ''
        );

        $cut = '';
        if (!empty($manifest['has_cut_dimension'])) {
            $cut = $this->price_calculator_select_html(
                'cut',
                __('Cut', 'loupe-diamond-network'),
                $this->price_calculator_cut_options($manifest),
                $this->price_calculator_pooled_key($manifest)
            );
        }

        $price = '<label class="ldn-price-calculator__field">'
            . '<span>' . esc_html__('Price you have been quoted', 'loupe-diamond-network')
            . ' <em>' . esc_html__('optional', 'loupe-diamond-network') . '</em></span>'
            . '<input type="text" inputmode="numeric" autocomplete="off"'
            . ' data-ldn-price-calculator-quote="1"'
            . ' placeholder="' . esc_attr($labels['currency'] . '0') . '">'
            . '</label>';

        return '<div class="ldn-price-calculator__controls">'
            . $colour . $clarity . $cut . $price
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
     * Options for a colour or clarity selector, straight from the manifest so a
     * group with no cells is never offered.
     *
     * @param array  $manifest
     * @param string $key colour_groups | clarity_groups
     * @return array
     */
    private function price_calculator_group_options(array $manifest, $key) {
        $groups = isset($manifest[$key]) && is_array($manifest[$key]) ? $manifest[$key] : array();
        $options = array();
        foreach ($groups as $group) {
            if (!is_array($group) || empty($group['key'])) {
                continue;
            }
            $label = isset($group['label']) && $group['label'] !== ''
                ? (string) $group['label']
                : (string) $group['key'];
            $members = isset($group['members']) && is_array($group['members'])
                ? $group['members']
                : array();
            // "G-H (G, H)" is noise when the label already spells the grade out.
            if (count($members) > 1 && $label !== implode(', ', $members)) {
                $label .= ' (' . implode(', ', $members) . ')';
            }
            $options[] = array('value' => (string) $group['key'], 'label' => $label);
        }
        return $options;
    }

    /**
     * Cut options: the pooled "not sure" choice first, then the graded values in
     * the quality order the manifest published them in.
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
            'label' => __('Any or not sure', 'loupe-diamond-network'),
        ));
        foreach ($grades as $grade) {
            $options[] = array('value' => (string) $grade, 'label' => (string) $grade);
        }
        return $options;
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
    private function price_calculator_result_html(array $manifest, array $cell, array $labels) {
        $currency = $labels['currency'];
        $percentiles = $cell['percentiles'];

        $typical = isset($percentiles['p50']) ? $percentiles['p50'] : null;
        $low = isset($percentiles['p25']) ? $percentiles['p25'] : null;
        $high = isset($percentiles['p75']) ? $percentiles['p75'] : null;

        $lines = array();
        if ($typical !== null) {
            $lines[] = '<p class="ldn-price-calculator__headline">'
                . esc_html(sprintf(
                    /* translators: %s: formatted price */
                    __('Typical for this spec: %s', 'loupe-diamond-network'),
                    $this->price_calculator_money($typical, $currency)
                ))
                . '</p>';
        }
        if ($low !== null && $high !== null) {
            $lines[] = '<p>' . esc_html(sprintf(
                /* translators: 1: lower price, 2: upper price */
                __('Half of them sell between %1$s and %2$s.', 'loupe-diamond-network'),
                $this->price_calculator_money($low, $currency),
                $this->price_calculator_money($high, $currency)
            )) . '</p>';
        }

        $spec = $this->price_calculator_spec_sentence($manifest, $cell);
        if ($spec !== '') {
            $lines[] = '<p class="ldn-price-calculator__basis">' . esc_html($spec) . '</p>';
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
     * "Based on 412 G-H, VS diamonds of any cut."
     *
     * The sample size is part of the claim, not a diagnostic: a percentile the
     * reader is asked to trust always says how many stones it rests on.
     *
     * @param array $manifest
     * @param array $cell
     * @return string
     */
    private function price_calculator_spec_sentence(array $manifest, array $cell) {
        $n = (int) $cell['sample_size'];
        if ($n < 1) {
            return '';
        }
        $spec = $this->price_calculator_spec_label($manifest, $cell);
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
    private function price_calculator_spec_label(array $manifest, array $cell) {
        $parts = array();
        $colour = $this->price_calculator_group_label($manifest, 'colour_groups', $cell['colour_group']);
        if ($colour !== '') {
            $parts[] = sprintf(
                /* translators: %s: colour group, e.g. G-H */
                __('%s colour', 'loupe-diamond-network'),
                $colour
            );
        }
        $clarity = $this->price_calculator_group_label($manifest, 'clarity_groups', $cell['clarity_group']);
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
     * @param string $key colour_groups | clarity_groups
     * @param string $group_key
     * @return string
     */
    private function price_calculator_group_label(array $manifest, $key, $group_key) {
        $groups = isset($manifest[$key]) && is_array($manifest[$key]) ? $manifest[$key] : array();
        foreach ($groups as $group) {
            if (is_array($group) && isset($group['key']) && (string) $group['key'] === (string) $group_key) {
                return isset($group['label']) ? (string) $group['label'] : (string) $group_key;
            }
        }
        return (string) $group_key;
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
    private function price_calculator_labels(array $manifest) {
        $currency = $this->currency_symbol(
            isset($manifest['currency']) ? $manifest['currency'] : 'USD'
        );

        $labels = array(
            'currency' => $currency,
            'typical'  => __('Typical for this spec: %price%', 'loupe-diamond-network'),
            'halfBetween' => __('Half of them sell between %low% and %high%.', 'loupe-diamond-network'),
            'basedOn'  => __('Based on %count% diamonds at %spec%.', 'loupe-diamond-network'),
            'basedOnOne' => __('Based on %count% diamond at %spec%.', 'loupe-diamond-network'),
            'colour'   => __('%s colour', 'loupe-diamond-network'),
            'clarity'  => __('%s clarity', 'loupe-diamond-network'),
            // Absent cell: say so plainly. Never answer from a coarser cell.
            'noCell'   => __(
                'We do not have enough diamonds at that exact specification to give a '
                . 'reliable figure. Try a wider colour or clarity group.',
                'loupe-diamond-network'
            ),
            // Verdicts for a quoted price, cheapest band first. Bands rather than
            // a single percentile: five published points cannot support one.
            'verdicts' => array(
                'below_p10' => __('That quote is cheaper than almost every diamond at this specification.', 'loupe-diamond-network'),
                'p10_p25'   => __('That quote is cheaper than about three quarters of them.', 'loupe-diamond-network'),
                'p25_p50'   => __('That quote is on the cheaper side of typical.', 'loupe-diamond-network'),
                'p50_p75'   => __('That quote is on the dearer side of typical.', 'loupe-diamond-network'),
                'p75_p90'   => __('That quote is dearer than about three quarters of them.', 'loupe-diamond-network'),
                'above_p90' => __('That quote is dearer than almost every diamond at this specification.', 'loupe-diamond-network'),
            ),
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
}
