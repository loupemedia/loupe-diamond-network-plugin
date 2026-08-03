<?php
/**
 * Standalone unit tests for LDN schema diagnostics (Stage 5).
 *
 * Run:  php loupe-diamond-network/tests/test-ldn-diagnostics.php
 *
 * Test intent: Staging diagnostics mark an artefact schema-behind when
 *              `_meta.schema_version` is missing or less than the catalogue
 *              version shipped in the config bundle, and size pages probe size
 *              artefacts (not the pricing default list).
 * Would fail if: an unstamped JSON body still showed schema "ok", or a
 *              size-individual page still probed summary_data_json.
 */

error_reporting(E_ALL);

define('ABSPATH', __DIR__ . '/');
define('LDN_PLUGIN_DIR', dirname(__DIR__) . '/');
define('LDN_VERSION', '0.19.0');

if (!function_exists('esc_html')) {
    function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (!function_exists('esc_attr')) {
    function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (!function_exists('esc_url')) {
    function esc_url($s) { return (string) $s; }
}

// Stub LDN_Config before loading LDN_Artefacts (constructor type-hint).
if (!class_exists('LDN_Config')) {
    class LDN_Config {
        private $bundle;
        public function __construct(array $bundle = array()) {
            $this->bundle = $bundle;
        }
        public function get_bundle() {
            return $this->bundle;
        }
        public function get_site($site_id) {
            return array('site_type' => 'pricing_authority');
        }
    }
}

require_once __DIR__ . '/../includes/class-ldn-page-context.php';
require_once __DIR__ . '/../includes/class-ldn-artefacts.php';
require_once __DIR__ . '/../includes/class-ldn-diagnostics.php';

// Minimal stubs so diagnostics helpers that reference dispatchers can load.
if (!class_exists('LDN_Dispatcher')) {
    class LDN_Dispatcher {
        const PRIMARY_ARTEFACT = array(
            'shape' => 'summary_data_json',
            'all-shapes' => 'shapes_ranking_json',
            'diamond-type' => 'type_summary_json',
            'top-level' => 'market_overview_json',
        );
    }
}
if (!class_exists('LDN_Size_Dispatcher')) {
    class LDN_Size_Dispatcher {
        const PRIMARY_ARTEFACT = array(
            'size-individual' => 'size_summary_json',
            'size-shape-hub' => 'size_summary_json',
        );
    }
}

$failures = 0;

function check($condition, $message) {
    global $failures;
    if ($condition) {
        echo "OK  {$message}\n";
        return;
    }
    echo "FAIL {$message}\n";
    $failures++;
}

// ---------------------------------------------------------------------------
// Schema verdict (pure)
// ---------------------------------------------------------------------------

check(
    LDN_Artefacts::schema_verdict(1, 1) === 'ok',
    'matching published and expected versions are ok'
);
check(
    LDN_Artefacts::schema_verdict(null, 1) === 'behind',
    'unstamped body (null published) is behind — the oval-page failure mode'
);
check(
    LDN_Artefacts::schema_verdict(1, 2) === 'behind',
    'published older than expected is behind'
);
check(
    LDN_Artefacts::schema_verdict(3, 2) === 'ahead',
    'published newer than the plugin catalogue is ahead'
);
check(
    LDN_Artefacts::schema_verdict(1, null) === 'undeclared',
    'missing catalogue version is undeclared, not silently ok'
);

check(
    LDN_Artefacts::published_schema_version(array('_meta' => array('schema_version' => 2))) === 2,
    'published_schema_version reads _meta.schema_version'
);
check(
    LDN_Artefacts::published_schema_version(array('lw_segments' => array())) === null,
    'body without _meta is unstamped'
);
check(
    LDN_Artefacts::published_schema_version(array('_meta' => array('schema_version' => '1'))) === null,
    'string schema_version is rejected (must be int)'
);

// ---------------------------------------------------------------------------
// Size page probes the size catalogue, not pricing defaults
// ---------------------------------------------------------------------------

$size_ids = LDN_Diagnostics::artefact_ids_for_level('size-individual');
check(
    in_array('size_summary_json', $size_ids, true),
    'size-individual probes size_summary_json'
);
check(
    !in_array('summary_data_json', $size_ids, true),
    'size-individual does not probe pricing summary_data_json'
);
check(
    LDN_Diagnostics::primary_artefact_for_level('size-individual') === 'size_summary_json',
    'size-individual primary is size_summary_json'
);
check(
    LDN_Diagnostics::primary_artefact_for_level('shape') === 'summary_data_json',
    'shape primary remains summary_data_json'
);

// ---------------------------------------------------------------------------
// Schema cell formatting for the panel
// ---------------------------------------------------------------------------

list($ok_label, $ok_bad) = LDN_Diagnostics::schema_cell(array(
    'schema_status' => 'ok',
    'published_schema' => 1,
    'expected_schema' => 1,
));
check($ok_label === 'ok (1=1)' && $ok_bad === false, 'ok schema cell is not highlighted');

list($behind_label, $behind_bad) = LDN_Diagnostics::schema_cell(array(
    'schema_status' => 'behind',
    'published_schema' => null,
    'expected_schema' => 1,
));
check(
    $behind_label === 'behind (pub=∅ expect=1)' && $behind_bad === true,
    'unstamped artefact shows behind with empty published marker'
);

list($na_label, $na_bad) = LDN_Diagnostics::schema_cell(array(
    'schema_status' => 'n/a',
));
check($na_label === '—' && $na_bad === false, 'non-JSON schema status renders as em dash');

// ---------------------------------------------------------------------------
// expected_schema_version from a stub config bundle
// ---------------------------------------------------------------------------

$artefacts = new LDN_Artefacts(new LDN_Config(array(
    'artefacts' => array(
        'artefacts' => array(
            'size_summary_json' => array(
                'schema_version' => 1,
                'page_level' => 'size',
                'status' => 'planned',
            ),
            'summary_data_json' => array(
                'page_level' => 'shape',
                // intentionally no schema_version
            ),
        ),
    ),
    'entitlements' => array('sites' => array()),
)));
check(
    $artefacts->expected_schema_version('size_summary_json') === 1,
    'expected_schema_version reads the bundle catalogue'
);
check(
    $artefacts->expected_schema_version('summary_data_json') === null,
    'missing schema_version in bundle is null (undeclared)'
);
check(
    $artefacts->expected_schema_version('not_a_real_artefact') === null,
    'unknown artefact id is null'
);

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} failure(s)\n");
    exit(1);
}
echo "\nAll diagnostics schema checks passed.\n";
exit(0);
