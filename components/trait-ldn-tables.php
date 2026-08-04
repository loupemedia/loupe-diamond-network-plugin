<?php
/**
 * Renderer component trait — split from class-ldn-renderer.php (CP53).
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

trait LDN_Trait_Tables {
    /**
     * Bar chart + linked ranking table for all-shapes hero.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return string
     */
    public function shapes_at_carat_hero_html(LDN_Page_Context $ctx, array $bag) {
        $html = $this->chart_html(
            is_array($bag['ranking_chart']) ? $bag['ranking_chart'] : array(),
            'ldn-shapes-ranking-chart',
            __('Prices by shape', 'loupe-diamond-network')
        );
        $html .= $this->shapes_ranking_table_html($ctx, $bag);
        return $html;
    }

    /**
     * Linked HTML table from shapes-ranking.json.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @param bool             $extended When true, include rank, sample size, and price range.
     * @return string
     */
    public function shapes_ranking_table_html(LDN_Page_Context $ctx, array $bag, $extended = false) {
        $payload = is_array($bag['ranking']) ? $bag['ranking'] : array();
        $rows = isset($payload['shapes']) && is_array($payload['shapes']) ? $payload['shapes'] : array();
        if (empty($rows)) {
            return '';
        }

        $currency = isset($payload['currency_symbol']) ? (string) $payload['currency_symbol'] : '$';
        $carat_label = $this->format_carat_label($ctx->carat);
        $country_name = $this->country_full_name($ctx);

        // The change column tracks the same period as the intro (C5.1 writes the
        // resolved label to `change_period`). An explicit null means the family
        // suppresses price-change, so the column is dropped; a missing key falls
        // back to the legacy 7-day column.
        if (array_key_exists('change_period', $payload)) {
            $change_period = is_string($payload['change_period']) ? $payload['change_period'] : null;
            $show_change_col = $change_period !== null;
        } else {
            $change_period = '7_days';
            $show_change_col = true;
        }

        $body = '';
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['shape'])) {
                continue;
            }
            $shape = (string) $row['shape'];
            $price = isset($row['median_price']) ? $row['median_price'] : (isset($row['current_price']) ? $row['current_price'] : null);
            $change = isset($row['price_change']) ? $row['price_change'] : (isset($row['change_7d']) ? $row['change_7d'] : null);
            $url = !empty($row['page_url'])
                ? (string) $row['page_url']
                : $this->build_price_page_url($ctx, 'shape', array('shape' => $shape));
            if ($url === '') {
                continue;
            }
            $price_cell = is_numeric($price) ? esc_html($currency . number_format((float) $price, 0)) : '—';
            $row_html = '';
            if ($extended && isset($row['rank']) && is_numeric($row['rank'])) {
                $row_html .= '<td>' . esc_html((string) (int) $row['rank']) . '</td>';
            }
            $row_html .= '<td><a href="' . esc_url($url) . '">' . esc_html($shape) . '</a></td>'
                . '<td>' . $price_cell . '</td>';
            if ($extended && isset($row['sample_size']) && is_numeric($row['sample_size'])) {
                $row_html .= '<td>' . esc_html(number_format((int) $row['sample_size'])) . '</td>';
            }
            if ($extended) {
                $price_min = isset($row['price_min']) ? $row['price_min'] : null;
                $price_max = isset($row['price_max']) ? $row['price_max'] : null;
                if (is_numeric($price_min) && is_numeric($price_max) && (float) $price_max > 0) {
                    $range_cell = esc_html(
                        $currency . number_format((float) $price_min, 0)
                        . '–' . $currency . number_format((float) $price_max, 0)
                    );
                } else {
                    $range_cell = '—';
                }
                $row_html .= '<td>' . $range_cell . '</td>';
            }
            if ($show_change_col) {
                $change_cell = is_numeric($change) ? esc_html(number_format((float) $change, 2) . '%') : '—';
                $row_html .= '<td>' . $change_cell . '</td>';
            }
            $body .= '<tr>' . $row_html . '</tr>';
        }
        if ($body === '') {
            return '';
        }

        $heading = sprintf(
            /* translators: 1: carat label, 2: country name */
            __('%1$s carat diamond prices in %2$s, ranked by shape', 'loupe-diamond-network'),
            $carat_label !== '' ? $carat_label : '1',
            $country_name
        );

        $change_header = '';
        if ($show_change_col) {
            $change_header = '<th>'
                . esc_html(sprintf(
                    /* translators: %s: change period adjective, e.g. "12-month" */
                    __('%s %% change', 'loupe-diamond-network'),
                    $this->change_period_short_label($change_period)
                ))
                . '</th>';
        }

        $price_header = $ctx->page_level === 'all-shapes'
            ? __('Typical price (median)', 'loupe-diamond-network')
            : __('Current price', 'loupe-diamond-network');

        $rank_header = $extended
            ? '<th>' . esc_html__('Rank', 'loupe-diamond-network') . '</th>'
            : '';
        $sample_header = $extended
            ? '<th>' . esc_html__('Diamonds', 'loupe-diamond-network') . '</th>'
            : '';
        $range_header = $extended
            ? '<th>' . esc_html__('Price range', 'loupe-diamond-network') . '</th>'
            : '';

        return '<section class="ldn-section ldn-shapes-ranking-table'
            . ($extended ? ' ldn-shapes-ranking-table--extended' : '') . '">'
            . '<h2>' . esc_html($heading) . '</h2>'
            . '<p>' . esc_html__('Click on any diamond shape to see more detailed pricing information.', 'loupe-diamond-network') . '</p>'
            . '<table class="ldn-data-table"><thead><tr>'
            . $rank_header
            . '<th>' . esc_html__('Shape', 'loupe-diamond-network') . '</th>'
            . '<th>' . esc_html($price_header) . '</th>'
            . $sample_header
            . $range_header
            . $change_header
            . '</tr></thead><tbody>' . $body . '</tbody></table></section>';
    }

    /**
     * Same-shape carat ladder with links to sibling carat pages.
     *
     * Reads C5.4 `carat-ladder.json`; highlights the page carat row.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @param string|null      $currency
     * @return string
     */
    public function carat_ladder_html(LDN_Page_Context $ctx, array $bag, $currency = null) {
        $payload = is_array($bag['carat_ladder']) ? $bag['carat_ladder'] : array();
        $rows = isset($payload['rows']) && is_array($payload['rows']) ? $payload['rows'] : array();
        if (empty($rows) || $ctx->shape === null) {
            return '';
        }

        $symbol = isset($payload['currency_symbol'])
            ? (string) $payload['currency_symbol']
            : $this->currency_symbol($currency);
        $shape_label = ucwords(str_replace('-', ' ', $ctx->shape));
        $body = '';
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['carat_weight'])) {
                continue;
            }
            $carat = (string) $row['carat_weight'];
            $price = isset($row['median_price']) ? $row['median_price'] : null;
            $is_page = !empty($row['is_page_carat']);
            $url = $is_page
                ? ''
                : $this->build_price_page_url($ctx, 'shape', array('carat' => $carat));
            $price_cell = is_numeric($price) ? esc_html($symbol . number_format((float) $price, 0)) : '—';
            $carat_cell = $url !== ''
                ? '<a href="' . esc_url($url) . '">' . esc_html($this->format_carat_label($carat) . ' ct') . '</a>'
                : esc_html($this->format_carat_label($carat) . ' ct');
            $row_class = $is_page ? ' class="ldn-row-current"' : '';
            $body .= '<tr' . $row_class . '><td>' . $carat_cell . '</td><td>' . $price_cell . '</td></tr>';
        }
        if ($body === '') {
            return '';
        }

        $all_shapes_url = $this->build_price_page_url($ctx, 'all-shapes');
        $compare_link = $all_shapes_url !== ''
            ? '<p><a href="' . esc_url($all_shapes_url) . '">'
                . esc_html__(
                    'Compare all shapes at this carat weight',
                    'loupe-diamond-network'
                ) . '</a></p>'
            : '';

        $intro_line = '<p>' . esc_html(
            sprintf(
                /* translators: %s: diamond shape name */
                __(
                    'How the median price of a %s diamond scales across carat weights — a useful way to weigh size against budget. Select a weight to see its full breakdown.',
                    'loupe-diamond-network'
                ),
                strtolower($shape_label)
            )
        ) . '</p>';

        $chart_html = $this->chart_html(
            isset($bag['carat_ladder_chart']) && is_array($bag['carat_ladder_chart'])
                ? $bag['carat_ladder_chart']
                : array(),
            'ldn-carat-ladder-chart',
            sprintf(
                /* translators: %s: diamond shape name */
                __('%s prices by carat weight', 'loupe-diamond-network'),
                $shape_label
            )
        );

        return '<section class="ldn-section ldn-carat-ladder">'
            . '<h2>' . esc_html(
                sprintf(
                    /* translators: %s: diamond shape name */
                    __('%s prices by carat weight', 'loupe-diamond-network'),
                    $shape_label
                )
            ) . '</h2>'
            . $intro_line
            . $compare_link
            . $chart_html
            . '<table class="ldn-data-table"><thead><tr>'
            . '<th>' . esc_html__('Carat', 'loupe-diamond-network') . '</th>'
            . '<th>' . esc_html__('Median price', 'loupe-diamond-network') . '</th>'
            . '</tr></thead><tbody>' . $body . '</tbody></table></section>';
    }

    /**
     * Colour x clarity price grid (heatmap table) for shape pages.
     *
     * Reads C5.7 `color-clarity.json`
     * (`price_table[color][clarity] = {price, count}`). Columns are colour grades
     * (D best -> worst), rows are clarity grades (FL/IF best -> worst); each cell
     * shows the average price for this shape + carat and is tinted by price
     * relative to the grid min/max via the site's `--ldn-primary` brand colour
     * (the `full_heatmap` presentation). Returns '' when the payload is absent or
     * empty, so non-entitled sites (the LDN_Data_Fetcher `color_clarity` gate
     * yields nothing) and stale folders drop the section cleanly.
     *
     * Canonical grade order is fixed here so the matrix always reads best ->
     * worst regardless of the JSON key order; grades not present in the data are
     * omitted, and any unrecognised colour grade is appended so no data is hidden.
     *
     * @param LDN_Page_Context $ctx
     * @param mixed            $payload  Decoded color-clarity.json (array) or null.
     * @param string|null      $currency Fallback currency code when payload omits it.
     * @return string
     */
    public function color_clarity_table_html(LDN_Page_Context $ctx, $payload, $currency = null) {
        if ($ctx->shape === null) {
            return '';
        }
        $payload = is_array($payload) ? $payload : array();
        $table = isset($payload['price_table']) && is_array($payload['price_table'])
            ? $payload['price_table']
            : array();
        if (empty($table)) {
            return '';
        }

        $color_order = array('D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M');
        $clarity_order = array('FL', 'IF', 'VVS1', 'VVS2', 'VS1', 'VS2', 'SI1', 'SI2', 'I1', 'I2', 'I3');

        $colors = array();
        foreach ($color_order as $color) {
            if (isset($table[$color]) && is_array($table[$color])) {
                $colors[] = $color;
            }
        }
        foreach (array_keys($table) as $color) {
            if (!in_array((string) $color, $colors, true) && is_array($table[$color])) {
                $colors[] = (string) $color;
            }
        }

        $clarities = array();
        foreach ($clarity_order as $clarity) {
            foreach ($colors as $color) {
                if (isset($table[$color][$clarity])) {
                    $clarities[] = $clarity;
                    break;
                }
            }
        }
        if (empty($colors) || empty($clarities)) {
            return '';
        }

        $min = null;
        $max = null;
        foreach ($colors as $color) {
            foreach ($clarities as $clarity) {
                $price = $this->color_clarity_cell_price($table, $color, $clarity);
                if ($price === null) {
                    continue;
                }
                if ($min === null || $price < $min) {
                    $min = $price;
                }
                if ($max === null || $price > $max) {
                    $max = $price;
                }
            }
        }

        $currency_code = isset($payload['currency']) ? (string) $payload['currency'] : (string) $currency;
        $symbol = $this->currency_symbol($currency_code !== '' ? $currency_code : $currency);
        $color_word = $this->locale_color_word($ctx);
        $color_label = ucfirst($color_word);

        $head = '<th scope="col">' . esc_html(sprintf('Clarity \\ %s', $color_label)) . '</th>';
        foreach ($colors as $color) {
            $head .= '<th scope="col">' . esc_html(strtoupper($color)) . '</th>';
        }

        $body = '';
        foreach ($clarities as $clarity) {
            $body .= '<tr><th scope="row">' . esc_html(strtoupper($clarity)) . '</th>';
            foreach ($colors as $color) {
                $price = $this->color_clarity_cell_price($table, $color, $clarity);
                if ($price === null) {
                    $body .= '<td class="ldn-cc-empty">—</td>';
                    continue;
                }
                $style = $this->color_clarity_cell_style($price, $min, $max);
                $body .= '<td' . $style . '>' . esc_html($symbol . number_format($price, 0)) . '</td>';
            }
            $body .= '</tr>';
        }

        return '<section class="ldn-section ldn-color-clarity">'
            . '<h2>' . esc_html(sprintf(__('Price by %s and clarity', 'loupe-diamond-network'), $color_word)) . '</h2>'
            . '<p>' . esc_html(sprintf(
                __(
                    'Average price for this shape and carat weight across the %s (D is the highest grade) and clarity grades we track. Darker cells cost more.',
                    'loupe-diamond-network'
                ),
                $color_word
            )) . '</p>'
            . '<div class="ldn-cc-scroll"><table class="ldn-data-table ldn-cc-table">'
            . '<thead><tr>' . $head . '</tr></thead>'
            . '<tbody>' . $body . '</tbody></table></div></section>';
    }

    /**
     * Read a single colour/clarity cell price from the C5.7 grid. Handles the
     * `{price, count}` cell contract and a bare-numeric fallback; returns null
     * when the cell is missing or non-numeric so the caller renders a dash.
     *
     * @param array  $table
     * @param string $color
     * @param string $clarity
     * @return float|null
     */
    private function color_clarity_cell_price(array $table, $color, $clarity) {
        if (!isset($table[$color][$clarity])) {
            return null;
        }
        $cell = $table[$color][$clarity];
        if (is_array($cell)) {
            return isset($cell['price']) && is_numeric($cell['price']) ? (float) $cell['price'] : null;
        }
        return is_numeric($cell) ? (float) $cell : null;
    }

    /**
     * Inline background style tinting a heatmap cell by price relative to the
     * grid min/max. Uses `color-mix()` over `--ldn-primary` so the heat colour
     * follows the site brand and degrades to no background (price text still
     * visible) on browsers without color-mix. Returns '' when the grid has no
     * spread (single price), so a flat grid is not falsely shaded.
     *
     * @param float      $price
     * @param float|null $min
     * @param float|null $max
     * @return string
     */
    private function color_clarity_cell_style($price, $min, $max) {
        if ($min === null || $max === null || $max <= $min) {
            return '';
        }
        $t = ($price - $min) / ($max - $min);
        $t = max(0.0, min(1.0, $t));
        $pct = (int) round(8 + ($t * 55));
        return ' style="background-color:color-mix(in srgb, var(--ldn-primary) ' . $pct . '%, transparent)"';
    }

    /**
     * Carat-tier comparison table for diamond-type pages.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @param string           $title
     * @return string
     */
    public function carat_tiers_table_html(LDN_Page_Context $ctx, array $bag, $title) {
        $payload = is_array($bag['type_summary']) ? $bag['type_summary'] : array();
        $tiers = isset($payload['carat_tiers']) && is_array($payload['carat_tiers']) ? $payload['carat_tiers'] : array();
        if (empty($tiers)) {
            return '';
        }

        $currency = $this->currency_symbol(isset($payload['currency']) ? $payload['currency'] : null);
        $anchor_carat = (float) $this->hub_anchor_carat();
        $body = '';
        foreach ($tiers as $tier) {
            if (!is_array($tier) || empty($tier['carat_weight'])) {
                continue;
            }
            $carat = (string) $tier['carat_weight'];
            $price = isset($tier['median_price']) ? $tier['median_price'] : null;
            $samples = isset($tier['sample_size']) ? (int) $tier['sample_size'] : 0;
            $url = !empty($tier['page_url'])
                ? (string) $tier['page_url']
                : $this->build_price_page_url($ctx, 'all-shapes', array('carat' => $carat));
            $price_cell = is_numeric($price) ? esc_html($currency . number_format((float) $price, 0)) : '—';
            $ppc_cell = '—';
            if (is_numeric($price) && is_numeric($carat) && (float) $carat > 0) {
                $ppc_cell = esc_html($currency . number_format((float) $price / (float) $carat, 0));
            }
            $link = $url !== ''
                ? '<a href="' . esc_url($url) . '">' . esc_html($carat . ' ct') . '</a>'
                : esc_html($carat . ' ct');
            $row_class = '';
            if (is_numeric($carat) && (float) $carat === $anchor_carat) {
                $row_class = ' class="ldn-row-highlight"';
            }
            $body .= '<tr' . $row_class . '><td data-label="' . esc_attr__('Carat', 'loupe-diamond-network') . '">'
                . $link . '</td><td data-label="' . esc_attr__('Typical price', 'loupe-diamond-network') . '">'
                . $price_cell . '</td><td data-label="' . esc_attr__('Price per carat', 'loupe-diamond-network') . '">'
                . $ppc_cell . '</td><td data-label="' . esc_attr__('Diamonds analysed', 'loupe-diamond-network') . '">'
                . esc_html(number_format($samples)) . '</td></tr>';
        }
        if ($body === '') {
            return '';
        }

        $table_intro = '';
        if ($ctx->site_id === 'ringspo') {
            $table_intro = $this->ringspo_carat_tiers_intro_html($ctx);
        }

        return '<section class="ldn-section ldn-carat-tiers-table">'
            . '<h2>' . esc_html($title) . '</h2>'
            . $table_intro
            . '<table class="ldn-data-table ldn-data-table--stacked"><thead><tr>'
            . '<th>' . esc_html__('Carat', 'loupe-diamond-network') . '</th>'
            . '<th>' . esc_html__('Typical price', 'loupe-diamond-network') . '</th>'
            . '<th>' . esc_html__('Price per carat', 'loupe-diamond-network') . '</th>'
            . '<th>' . esc_html__('Diamonds analysed', 'loupe-diamond-network') . '</th>'
            . '</tr></thead><tbody>' . $body . '</tbody></table></section>';
    }

    /**
     * Ringspo how-to-read + price-per-carat commentary above the type carat table.
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    private function ringspo_carat_tiers_intro_html(LDN_Page_Context $ctx) {
        $how_to_read = __(
            'Each figure combines the median price of every shape we track at that '
            . 'weight. Shapes with more diamonds on the market count for more, so the '
            . 'figure stays close to what is actually being sold. '
            . 'Click a carat to see how prices vary by shape, then open a shape page '
            . 'to see how colour, clarity and cut affect what you pay.',
            'loupe-diamond-network'
        );
        if ($ctx->diamond_type === 'lab-grown') {
            $ppc = __(
                'Lab-grown diamond prices do not increase proportionally with weight. '
                . 'Larger stones typically cost more per carat, and prices can also jump '
                . 'around popular milestone weights such as 1, 1.5 and 2 carats.',
                'loupe-diamond-network'
            );
        } else {
            $ppc = __(
                'Natural diamond prices do not increase proportionally with weight. '
                . 'Larger diamonds are rarer, so the price per carat generally increases '
                . 'as diamonds get bigger. Prices can also jump around popular milestone '
                . 'weights such as 1, 1.5 and 2 carats.',
                'loupe-diamond-network'
            );
        }
        return $this->format_prose_html($how_to_read . "\n\n" . $ppc);
    }

    /**
     * Most-traded shape/carat combinations for the top-level hub (C5.3 top-tables.json).
     *
     * Doubles as the hub's internal-link block: every row links to the shape page for
     * that exact combination, so the deepest pages are one click from the hub.
     *
     * The prices here are true medians for a single shape at a single weight (C3's p50
     * over that combination's listings), unlike the carat table higher up the page,
     * whose figure combines every shape at that weight. That is why this table says
     * "Median price" where the carat table says "Typical price".
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return string
     */
    public function most_traded_table_html(LDN_Page_Context $ctx, array $bag) {
        $top = isset($bag['top_tables']) && is_array($bag['top_tables'])
            ? $bag['top_tables']
            : array();
        if (empty($top)) {
            return '';
        }

        $currency = $this->currency_symbol(
            isset($top['metadata']['currency'])
                ? $top['metadata']['currency']
                : $this->config->get_currency($ctx->site_id, $ctx->country_code)
        );

        $groups = array(
            'natural_top'   => __('Natural', 'loupe-diamond-network'),
            'lab_grown_top' => __('Lab-grown', 'loupe-diamond-network'),
        );

        $blocks = '';
        foreach ($groups as $key => $group_label) {
            $rows = isset($top[$key]) && is_array($top[$key]) ? $top[$key] : array();
            $body = '';
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $shape = isset($row['shape']) ? (string) $row['shape'] : '';
                $carat = isset($row['carat']) ? (string) $row['carat'] : '';
                if ($shape === '' || $carat === '') {
                    continue;
                }
                $dtype = isset($row['diamond_type']) ? (string) $row['diamond_type'] : 'natural';
                $label = $this->format_carat_label($carat) . ' ct '
                    . ucwords(str_replace('-', ' ', $shape));
                $url = $this->build_price_page_url(
                    $ctx,
                    'shape',
                    array('type' => $dtype, 'carat' => $carat, 'shape' => $shape)
                );
                $name_cell = $url !== ''
                    ? '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>'
                    : esc_html($label);
                $price_cell = isset($row['median_price']) && is_numeric($row['median_price'])
                    ? esc_html($currency . number_format((float) $row['median_price'], 0))
                    : '—';
                $samples = isset($row['sample_size']) ? (int) $row['sample_size'] : 0;
                $body .= '<tr><td data-label="' . esc_attr__('Diamond', 'loupe-diamond-network') . '">'
                    . $name_cell . '</td><td data-label="' . esc_attr__('Median price', 'loupe-diamond-network') . '">'
                    . $price_cell . '</td><td data-label="' . esc_attr__('Diamonds analysed', 'loupe-diamond-network') . '">'
                    . esc_html(number_format($samples)) . '</td></tr>';
            }
            if ($body === '') {
                continue;
            }
            $blocks .= '<div class="ldn-most-traded-group">'
                . '<h3>' . esc_html($group_label) . '</h3>'
                . '<table class="ldn-data-table ldn-data-table--stacked"><thead><tr>'
                . '<th>' . esc_html__('Diamond', 'loupe-diamond-network') . '</th>'
                . '<th>' . esc_html__('Median price', 'loupe-diamond-network') . '</th>'
                . '<th>' . esc_html__('Diamonds analysed', 'loupe-diamond-network') . '</th>'
                . '</tr></thead><tbody>' . $body . '</tbody></table></div>';
        }

        if ($blocks === '') {
            return '';
        }

        $intro = __(
            'These are the shape and weight combinations with the most diamonds on the '
            . 'market, which makes them the sizes buyers are actually choosing. Each '
            . 'price is the median for that one shape at that one weight, so it is a '
            . 'firmer number than the all-shapes figures higher up this page.',
            'loupe-diamond-network'
        );

        return '<section class="ldn-section ldn-most-traded">'
            . '<h2>' . esc_html__('The most traded diamonds right now', 'loupe-diamond-network') . '</h2>'
            . $this->format_prose_html($intro)
            . '<div class="ldn-most-traded-grid">' . $blocks . '</div>'
            . '</section>';
    }

    /**
     * Price-per-carat curve for the diamond-type page (C5.2).
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return string
     */
    public function price_per_carat_chart_html(LDN_Page_Context $ctx, array $bag) {
        $fallback = $this->price_per_carat_chart_fallback($ctx, $bag);
        $chart = $this->chart_html(
            isset($bag['price_per_carat_chart']) && is_array($bag['price_per_carat_chart'])
                ? $bag['price_per_carat_chart']
                : array(),
            'ldn-price-per-carat-chart',
            __('Price per carat by weight', 'loupe-diamond-network'),
            $fallback
        );
        if ($chart === '') {
            return '';
        }

        $type_label = isset(self::$TYPE_LABELS[$ctx->diamond_type])
            ? self::$TYPE_LABELS[$ctx->diamond_type]
            : ucfirst(str_replace('-', ' ', $ctx->diamond_type));

        if ($ctx->diamond_type === 'lab-grown') {
            $intro = __(
                'A bigger lab-grown diamond usually costs more for every carat it weighs, '
                . 'not just more in total. The climb is gentler than it is for natural '
                . 'diamonds, because growing a larger stone is a question of time rather '
                . 'than luck.',
                'loupe-diamond-network'
            );
        } else {
            $intro = __(
                'A bigger natural diamond costs more for every carat it weighs, not just '
                . 'more in total. Large rough is genuinely scarce, so the price of a single '
                . 'carat climbs steadily with the size of the finished stone.',
                'loupe-diamond-network'
            );
        }
        $milestone = __(
            'Look for the steps around 1, 1.5 and 2 carats. Buyers cluster at those '
            . 'weights, so a diamond that just reaches one of them costs more per carat '
            . 'than a slightly lighter stone. Buying a little under a milestone weight is '
            . 'usually the cheapest way to get a given look.',
            'loupe-diamond-network'
        );

        return '<section class="ldn-section ldn-price-per-carat">'
            . '<h2>' . esc_html(
                sprintf(
                    /* translators: %s: diamond type label (Natural / Lab-Grown) */
                    __('%s diamond price per carat by weight', 'loupe-diamond-network'),
                    $type_label
                )
            ) . '</h2>'
            . $this->format_prose_html($intro . "\n\n" . $milestone)
            . $chart
            . '</section>';
    }

    /**
     * Natural vs lab-grown overview table for top-level pages.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return string
     */
    public function market_overview_table_html(LDN_Page_Context $ctx, array $bag) {
        $overview = is_array($bag['market_overview']) ? $bag['market_overview'] : array();
        if (empty($overview)) {
            return $this->stats_html($ctx, is_array($bag['summary']) ? $bag['summary'] : array(), null);
        }

        $currency = $this->currency_symbol(
            isset($overview['currency']) ? $overview['currency'] : $this->config->get_currency($ctx->site_id, $ctx->country_code)
        );

        $trend_chart = $this->chart_html(
            isset($bag['market_trend_chart']) && is_array($bag['market_trend_chart'])
                ? $bag['market_trend_chart']
                : array(),
            'ldn-market-trend-chart',
            __('Natural vs lab-grown price change (%)', 'loupe-diamond-network')
        );

        $type_comparison = $this->section_value('type_comparison', $ctx, $bag);
        $table_intro = '';
        if ($ctx->site_id === 'ringspo' && $ctx->page_level === 'top-level') {
            $table_intro = $this->format_prose_html(
                __(
                    "Both natural and lab-grown diamond prices are constantly changing, as is the relationship between the two.\n\n"
                    . 'Each price below combines every shape we track at that weight, with shapes that have more '
                    . 'diamonds on the market counting for more. Within those figures there is considerable variation '
                    . 'depending on shape and quality, so the price you can expect to pay for your diamond may be very '
                    . 'different depending on what you are looking for. The number of diamonds behind each row is shown '
                    . 'on the right. Larger weights are bought and sold far less often, so we find fewer of them and '
                    . 'their prices move around more.',
                    'loupe-diamond-network'
                )
            );
        } elseif (is_string($type_comparison) && trim($type_comparison) !== '') {
            $table_intro = $this->format_prose_html($type_comparison);
        }

        $table_html = $this->carat_price_table_html($ctx, $overview, $currency, $table_intro);

        $discount_intro = sprintf(
            /* translators: %s: country name */
            __(
                'Lab-grown diamonds typically cost less than natural at every carat weight. '
                . 'This chart shows how wide that gap is across sizes in %s.',
                'loupe-diamond-network'
            ),
            $this->country_full_name($ctx)
        );
        $discount_fallback = $this->lab_grown_discount_chart_fallback($overview);
        $discount_chart = $this->chart_html(
            isset($bag['market_discount_chart']) && is_array($bag['market_discount_chart'])
                ? $bag['market_discount_chart']
                : array(),
            'ldn-market-discount-chart',
            __('Lab-grown discount vs natural', 'loupe-diamond-network'),
            $discount_fallback
        );
        $discount_block = '';
        if ($discount_chart !== '') {
            $discount_block = '<section class="ldn-section ldn-discount-chart">'
                . '<p class="ldn-discount-chart-intro">' . esc_html($discount_intro) . '</p>'
                . $discount_chart
                . '</section>';
        }

        return $trend_chart . $table_html . $discount_block;
    }

    /**
     * Per-carat natural vs lab-grown navigation table for top-level pages.
     *
     * Reads the C5.3 `carat_price_table` from market-overview.json; each price
     * links down to that type+carat all-shapes page so the hub is navigable.
     * Returns '' when the artefact predates the table (graceful on stale S3).
     *
     * The stone count is rendered alongside the prices because the top and bottom
     * of the carat range are not comparably sampled: a heavier weight resting on
     * tens of stones can read as cheaper than a lighter one resting on thousands.
     * We publish every weight we have data for, so the count is what makes such a
     * row interpretable rather than apparently contradictory. Absent on artefacts
     * written before C5.3 carried it — the column then degrades to an em dash.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $overview market-overview payload
     * @param string           $currency resolved currency symbol
     * @return string
     */
    public function carat_price_table_html(LDN_Page_Context $ctx, array $overview, $currency, $intro_html = '') {
        $rows = isset($overview['carat_price_table']) && is_array($overview['carat_price_table'])
            ? $overview['carat_price_table']
            : array();
        if (empty($rows)) {
            return '';
        }

        $highlight_carat = $ctx->page_level === 'top-level' ? (float) $this->hub_anchor_carat() : null;
        $body = '';
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['carat_weight']) || (string) $row['carat_weight'] === '') {
                continue;
            }
            $carat = (string) $row['carat_weight'];
            $carat_label = $this->format_carat_label($carat);
            $nat = isset($row['natural_median_price']) ? $row['natural_median_price'] : null;
            $lab = isset($row['lab_grown_median_price']) ? $row['lab_grown_median_price'] : null;
            $discount = isset($row['lab_grown_discount_pct']) ? $row['lab_grown_discount_pct'] : null;

            $nat_cell = $this->carat_price_cell($ctx, 'natural', $carat, $nat, $currency);
            $lab_cell = $this->carat_price_cell($ctx, 'lab-grown', $carat, $lab, $currency);
            $discount_cell = is_numeric($discount) ? esc_html(number_format((float) $discount, 1) . '%') : '—';

            $samples = 0;
            foreach (array('natural_sample_size', 'lab_grown_sample_size') as $field) {
                if (isset($row[$field]) && is_numeric($row[$field])) {
                    $samples += (int) $row[$field];
                }
            }
            $samples_cell = $samples > 0 ? esc_html(number_format($samples)) : '—';

            $row_class = '';
            if ($highlight_carat !== null && (float) $carat === $highlight_carat) {
                $row_class = ' class="ldn-row-highlight"';
            }
            $body .= '<tr' . $row_class . '><td>' . esc_html($carat_label . ' ct') . '</td><td>' . $nat_cell
                . '</td><td>' . $lab_cell . '</td><td>' . $discount_cell . '</td><td>'
                . $samples_cell . '</td></tr>';
        }
        if ($body === '') {
            return '';
        }

        $heading = ($ctx->site_id === 'ringspo' && $ctx->page_level === 'top-level')
            ? __('Natural vs lab-grown prices by carat', 'loupe-diamond-network')
            : sprintf(
                /* translators: %s: country name */
                __('Natural vs lab-grown diamond prices in %s by carat weight', 'loupe-diamond-network'),
                $this->country_full_name($ctx)
            );

        return '<section class="ldn-section ldn-carat-price-table">'
            . '<h2>' . esc_html($heading) . '</h2>'
            . ($intro_html !== '' ? '<div class="ldn-carat-price-table-intro">' . $intro_html . '</div>' : '')
            . '<p class="ldn-carat-price-table-hint">' . esc_html__('Select any price to explore that carat weight in more detail.', 'loupe-diamond-network') . '</p>'
            . '<table class="ldn-data-table"><thead><tr>'
            . '<th>' . esc_html__('Carat weight', 'loupe-diamond-network') . '</th>'
            . '<th>' . esc_html__('Natural', 'loupe-diamond-network') . '</th>'
            . '<th>' . esc_html__('Lab-grown', 'loupe-diamond-network') . '</th>'
            . '<th>' . esc_html__('Lab-grown discount', 'loupe-diamond-network') . '</th>'
            . '<th>' . esc_html__('Diamonds analysed', 'loupe-diamond-network') . '</th>'
            . '</tr></thead><tbody>' . $body . '</tbody></table></section>';
    }

    /**
     * One price cell for the carat price table — linked to the type+carat
     * all-shapes page when resolvable, else the bare price.
     *
     * @param LDN_Page_Context $ctx
     * @param string           $type  natural|lab-grown
     * @param string           $carat numeric carat label
     * @param mixed            $price median price (numeric or null)
     * @param string           $currency currency symbol
     * @return string
     */
    private function carat_price_cell(LDN_Page_Context $ctx, $type, $carat, $price, $currency) {
        if (!is_numeric($price)) {
            return '—';
        }
        $formatted = esc_html($currency . number_format((float) $price, 0));
        $url = $this->build_price_page_url($ctx, 'all-shapes', array('type' => $type, 'carat' => $carat));
        if ($url === '') {
            return $formatted;
        }
        return '<a href="' . esc_url($url) . '">' . $formatted . '</a>';
    }

    /**
     * Plain-text summary of price-per-carat at anchor weights for the PPC chart's
     * no-JS fallback (CP54_04).
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return string
     */
    private function price_per_carat_chart_fallback(LDN_Page_Context $ctx, array $bag) {
        $payload = isset($bag['type_summary']) && is_array($bag['type_summary'])
            ? $bag['type_summary']
            : array();
        $currency = $this->currency_symbol(isset($payload['currency']) ? $payload['currency'] : null);
        $parts = array();
        foreach (array('1', '2') as $carat) {
            $tier = $this->type_summary_carat_tier($payload, $carat);
            $price = isset($tier['median_price']) ? $tier['median_price'] : null;
            if (!is_numeric($price) || !is_numeric($carat) || (float) $carat <= 0) {
                continue;
            }
            $ppc = (float) $price / (float) $carat;
            $parts[] = sprintf(
                '%s ct: %s',
                $this->format_carat_label($carat),
                $currency . number_format($ppc, 0)
            );
        }
        if ($parts === array()) {
            return '';
        }
        return sprintf(
            /* translators: %s: semicolon-separated list like "1 ct: $3,093; 2 ct: $7,259" */
            __('Price per carat — %s.', 'loupe-diamond-network'),
            implode('; ', $parts)
        );
    }

    /**
     * Plain-text summary of lab-grown discount at anchor carat weights for the
     * discount chart's no-JS fallback (CP54_04).
     *
     * @param array $overview market-overview payload
     * @return string
     */
    private function lab_grown_discount_chart_fallback(array $overview) {
        $parts = array();
        foreach (array('1', '2') as $carat) {
            $row = $this->hub_carat_table_row($overview, $carat);
            $discount = isset($row['lab_grown_discount_pct']) ? $row['lab_grown_discount_pct'] : null;
            if (!is_numeric($discount)) {
                continue;
            }
            $parts[] = sprintf(
                '%s ct: %.1f%%',
                $this->format_carat_label($carat),
                (float) $discount
            );
        }
        if ($parts === array()) {
            return '';
        }
        return sprintf(
            /* translators: %s: comma-separated list like "1 ct: 69.0%, 2 ct: 81.9%" */
            __('Lab-grown discount vs natural — %s.', 'loupe-diamond-network'),
            implode('; ', $parts)
        );
    }
}
