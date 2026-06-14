<?php
/**
 * Simulated test script for native GitHub updater functionality.
 */

namespace {
    // 1. Mock WordPress constants and environment
    if (!defined('ABSPATH')) {
        define('ABSPATH', dirname(dirname(__FILE__)) . '/');
    }
    if (!defined('DRW_VERSION')) {
        define('DRW_VERSION', '1.0.0');
    }
    if (!defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
    }
    if (!defined('DRW_PLUGIN_BASENAME')) {
        define('DRW_PLUGIN_BASENAME', 'discount-rules-woo/discount-rules-woo.php');
    }

    // Mock global storage
    global $wp_filters, $mock_transients, $mock_remote_get_response, $wp_filesystem;
    $wp_filters = [];
    $mock_transients = [];
    $mock_remote_get_response = null;

    // Mock WP_Error
    class WP_Error {
        public $code;
        public $message;
        public function __construct($code = '', $message = '') {
            $this->code = $code;
            $this->message = $message;
        }
    }

    // Mock Filesystem
    class MockWPFilesystem {
        public $moved_files = [];
        public function move($source, $target, $overwrite = false) {
            $this->moved_files[] = [
                'source' => $source,
                'target' => $target,
                'overwrite' => $overwrite
            ];
            return true;
        }
    }
    $wp_filesystem = new MockWPFilesystem();

    // Mock functions
    function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {
        global $wp_filters;
        $wp_filters[$tag][] = [
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args
        ];
        return true;
    }

    function apply_filters($tag, $value, ...$args) {
        global $wp_filters;
        if (isset($wp_filters[$tag])) {
            foreach ($wp_filters[$tag] as $hook) {
                $value = call_user_func($hook['callback'], $value, ...$args);
            }
        }
        return $value;
    }

    function get_transient($transient) {
        global $mock_transients;
        return isset($mock_transients[$transient]) ? $mock_transients[$transient] : false;
    }

    function set_transient($transient, $value, $expiration = 0) {
        global $mock_transients;
        $mock_transients[$transient] = $value;
        return true;
    }

    function wp_remote_get($url, $args = []) {
        global $mock_remote_get_response;
        return $mock_remote_get_response;
    }

    function wp_remote_retrieve_response_code($response) {
        if (is_array($response) && isset($response['response']['code'])) {
            return $response['response']['code'];
        }
        return 0;
    }

    function wp_remote_retrieve_body($response) {
        if (is_array($response) && isset($response['body'])) {
            return $response['body'];
        }
        return '';
    }

    function get_bloginfo($show = '') {
        if ($show === 'version') {
            return '6.0';
        }
        return '';
    }

    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }

    function wp_kses_post($data) {
        return $data;
    }

    function trailingslashit($string) {
        return rtrim($string, '/\\') . '/';
    }

    function plugin_basename($file) {
        return 'discount-rules-woo/discount-rules-woo.php';
    }

    function WP_Filesystem() {
        return true;
    }

    // Register autoloading for Drw\App
    spl_autoload_register(function ($class) {
        $prefix = 'Drw\\App\\';
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        $relative_class = substr($class, $len);
        $file = dirname(dirname(__FILE__)) . '/src/' . str_replace('\\', '/', $relative_class) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
}

namespace {
    // 2. Test Execution
    $updater = \Drw\App\Controllers\Updater::instance();
    $updater->register_hooks();

    $all_passed = true;

    echo "====================================================\n";
    echo "Running native GitHub updater tests...\n";
    echo "====================================================\n\n";

    // ----------------------------------------------------
    // Test Case 1: Version check when up-to-date (no update object in transient response)
    // ----------------------------------------------------
    echo "Running Test Case 1: Version check when up-to-date...\n";
    
    // Scenario 1A: Remote version same as local (1.0.0)
    $release_same = new \stdClass();
    $release_same->tag_name = 'v1.0.0';
    $release_same->zipball_url = 'https://github.com/bywuilgonzalez-co/discount-rules-woo/archive/refs/tags/v1.0.0.zip';
    $release_same->body = 'Same version';
    
    $mock_transients['drw_github_latest_release'] = $release_same;

    $transient = new \stdClass();
    $transient->checked = [DRW_PLUGIN_BASENAME => DRW_VERSION];
    $transient->response = [];

    $result = $updater->check_update($transient);
    
    if (empty($result->response[DRW_PLUGIN_BASENAME])) {
        echo "[PASS] Same version (1.0.0) correctly ignored.\n";
    } else {
        echo "[FAIL] Same version (1.0.0) incorrectly added to response.\n";
        $all_passed = false;
    }

    // Scenario 1B: Remote version lower than local (0.9.0)
    $release_lower = new \stdClass();
    $release_lower->tag_name = 'v0.9.0';
    $release_lower->zipball_url = 'https://github.com/bywuilgonzalez-co/discount-rules-woo/archive/refs/tags/v0.9.0.zip';
    $release_lower->body = 'Older version';
    
    $mock_transients['drw_github_latest_release'] = $release_lower;

    $transient = new \stdClass();
    $transient->checked = [DRW_PLUGIN_BASENAME => DRW_VERSION];
    $transient->response = [];

    $result = $updater->check_update($transient);

    if (empty($result->response[DRW_PLUGIN_BASENAME])) {
        echo "[PASS] Lower version (0.9.0) correctly ignored.\n";
    } else {
        echo "[FAIL] Lower version (0.9.0) incorrectly added to response.\n";
        $all_passed = false;
    }
    echo "\n";


    // ----------------------------------------------------
    // Test Case 2: Version check when update is available (correctly adds update object with new version, url, and zip package)
    // ----------------------------------------------------
    echo "Running Test Case 2: Version check when update is available...\n";

    // Scenario 2A: Update available via cache
    $release_newer = new \stdClass();
    $release_newer->tag_name = 'v1.1.0';
    $release_newer->zipball_url = 'https://github.com/bywuilgonzalez-co/discount-rules-woo/archive/refs/tags/v1.1.0.zip';
    $release_newer->body = 'Newer version body';
    
    $mock_transients['drw_github_latest_release'] = $release_newer;

    $transient = new \stdClass();
    $transient->checked = [DRW_PLUGIN_BASENAME => DRW_VERSION];
    $transient->response = [];

    $result = $updater->check_update($transient);

    if (!empty($result->response[DRW_PLUGIN_BASENAME])) {
        $update_info = $result->response[DRW_PLUGIN_BASENAME];
        $passed_2a = true;
        if ($update_info->new_version !== '1.1.0') {
            echo "[FAIL] Expected new_version to be '1.1.0', got '{$update_info->new_version}'\n";
            $passed_2a = false;
        }
        if ($update_info->package !== $release_newer->zipball_url) {
            echo "[FAIL] Expected package URL to match zipball_url, got '{$update_info->package}'\n";
            $passed_2a = false;
        }
        if ($update_info->slug !== 'discount-rules-woo') {
            echo "[FAIL] Expected slug to be 'discount-rules-woo', got '{$update_info->slug}'\n";
            $passed_2a = false;
        }
        
        if ($passed_2a) {
            echo "[PASS] Update details correctly set in transient response.\n";
        } else {
            $all_passed = false;
        }
    } else {
        echo "[FAIL] Newer version (1.1.0) was not added to response.\n";
        $all_passed = false;
    }

    // Scenario 2B: Update available, transient cache empty, triggers HTTP request
    $mock_transients['drw_github_latest_release'] = false; // clear cache

    $mock_remote_get_response = [
        'response' => ['code' => 200],
        'body' => json_encode([
            'tag_name' => 'v1.2.0',
            'zipball_url' => 'https://github.com/bywuilgonzalez-co/discount-rules-woo/archive/refs/tags/v1.2.0.zip',
            'body' => "V1.2.0 Changelog\n- Fixes some more issues."
        ])
    ];

    $transient = new \stdClass();
    $transient->checked = [DRW_PLUGIN_BASENAME => DRW_VERSION];
    $transient->response = [];

    $result = $updater->check_update($transient);

    if (!empty($result->response[DRW_PLUGIN_BASENAME])) {
        $update_info = $result->response[DRW_PLUGIN_BASENAME];
        $passed_2b = true;
        if ($update_info->new_version !== '1.2.0') {
            echo "[FAIL] Expected remote version '1.2.0' from HTTP request, got '{$update_info->new_version}'\n";
            $passed_2b = false;
        }
        // Verify it was saved to transient cache
        $cached_release = get_transient('drw_github_latest_release');
        if (!$cached_release || $cached_release->tag_name !== 'v1.2.0') {
            echo "[FAIL] GitHub response was not cached in transient 'drw_github_latest_release'.\n";
            $passed_2b = false;
        }
        
        if ($passed_2b) {
            echo "[PASS] Transient cache populated and update details retrieved successfully from mock HTTP call.\n";
        } else {
            $all_passed = false;
        }
    } else {
        echo "[FAIL] Newer version from HTTP request (1.2.0) was not added to response.\n";
        $all_passed = false;
    }
    echo "\n";


    // ----------------------------------------------------
    // Test Case 3: Plugin info details retrieval
    // ----------------------------------------------------
    echo "Running Test Case 3: Plugin info details retrieval...\n";

    // Setup active release in cache
    $release_details = new \stdClass();
    $release_details->tag_name = 'v1.2.0';
    $release_details->zipball_url = 'https://github.com/bywuilgonzalez-co/discount-rules-woo/archive/refs/tags/v1.2.0.zip';
    $release_details->body = "Feature list:\n* Dynamic BOGO\n* Free Shipping";
    
    $mock_transients['drw_github_latest_release'] = $release_details;

    // Call plugins_api_handler
    $args = new \stdClass();
    $args->slug = 'discount-rules-woo';

    $res = $updater->plugins_api_handler(false, 'plugin_information', $args);

    if ($res instanceof \stdClass) {
        $passed_3 = true;
        if ($res->slug !== 'discount-rules-woo') {
            echo "[FAIL] Expected slug 'discount-rules-woo', got '{$res->slug}'\n";
            $passed_3 = false;
        }
        if ($res->version !== '1.2.0') {
            echo "[FAIL] Expected version '1.2.0', got '{$res->version}'\n";
            $passed_3 = false;
        }
        if ($res->download_link !== $release_details->zipball_url) {
            echo "[FAIL] Expected download link to be '{$release_details->zipball_url}', got '{$res->download_link}'\n";
            $passed_3 = false;
        }
        if (empty($res->sections['changelog']) || strpos($res->sections['changelog'], '<br') === false) {
            echo "[FAIL] Expected changelog section to have formatting (nl2br), got: " . var_export($res->sections['changelog'], true) . "\n";
            $passed_3 = false;
        }
        
        if ($passed_3) {
            echo "[PASS] Plugin information fields populated and formatted correctly.\n";
        } else {
            $all_passed = false;
        }
    } else {
        echo "[FAIL] plugins_api_handler did not return an object.\n";
        $all_passed = false;
    }
    echo "\n";


    // ----------------------------------------------------
    // Test Case 4: Temporary directory renaming hook logic (rename_github_folder)
    // ----------------------------------------------------
    echo "Running Test Case 4: Temporary directory renaming hook logic...\n";

    // Scenario 4A: Rename matches our plugin (by slug)
    $wp_filesystem->moved_files = []; // Reset moved files log
    $source = '/var/www/wp-content/upgrade/discount-rules-woo-v1.2.0';
    $remote_source = '/var/www/wp-content/upgrade';
    $upgrader = new \stdClass();
    $hook_extra = ['slug' => 'discount-rules-woo'];

    $result_path = $updater->rename_github_folder($source, $remote_source, $upgrader, $hook_extra);

    $expected_target = '/var/www/wp-content/upgrade/discount-rules-woo';
    $expected_result = '/var/www/wp-content/upgrade/discount-rules-woo/'; // because of trailingslashit

    if ($result_path === $expected_result) {
        $passed_4a = true;
        if (count($wp_filesystem->moved_files) !== 1) {
            echo "[FAIL] Expected exactly 1 file move, recorded " . count($wp_filesystem->moved_files) . "\n";
            $passed_4a = false;
        } else {
            $move = $wp_filesystem->moved_files[0];
            if ($move['source'] !== $source) {
                echo "[FAIL] Expected move source '{$source}', got '{$move['source']}'\n";
                $passed_4a = false;
            }
            if ($move['target'] !== $expected_target) {
                echo "[FAIL] Expected move target '{$expected_target}', got '{$move['target']}'\n";
                $passed_4a = false;
            }
        }
        
        if ($passed_4a) {
            echo "[PASS] Folder renamed to 'discount-rules-woo' and trailingslashit path returned.\n";
        } else {
            $all_passed = false;
        }
    } else {
        echo "[FAIL] Expected return path '{$expected_result}', got '{$result_path}'\n";
        $all_passed = false;
    }

    // Scenario 4B: Renaming a different plugin (should ignore)
    $wp_filesystem->moved_files = []; // Reset
    $source_other = '/var/www/wp-content/upgrade/some-other-plugin-v1.0';
    $hook_extra_other = ['slug' => 'some-other-plugin'];

    $result_path_other = $updater->rename_github_folder($source_other, $remote_source, $upgrader, $hook_extra_other);

    if ($result_path_other === $source_other) {
        if (count($wp_filesystem->moved_files) === 0) {
            echo "[PASS] Different plugin was correctly ignored and path left unmodified.\n";
        } else {
            echo "[FAIL] Different plugin triggered a file move.\n";
            $all_passed = false;
        }
    } else {
        echo "[FAIL] Different plugin returned modified path: '{$result_path_other}'\n";
        $all_passed = false;
    }

    // Scenario 4C: Folder already named correctly (should ignore)
    $wp_filesystem->moved_files = []; // Reset
    $source_same = '/var/www/wp-content/upgrade/discount-rules-woo';
    $result_path_same = $updater->rename_github_folder($source_same, $remote_source, $upgrader, $hook_extra);

    if ($result_path_same === $source_same) {
        if (count($wp_filesystem->moved_files) === 0) {
            echo "[PASS] Already correctly named folder was ignored without renaming.\n";
        } else {
            echo "[FAIL] Already correctly named folder triggered a file move.\n";
            $all_passed = false;
        }
    } else {
        echo "[FAIL] Already correctly named folder returned modified path: '{$result_path_same}'\n";
        $all_passed = false;
    }
    echo "\n";


    // ----------------------------------------------------
    // Final Results Summary
    // ----------------------------------------------------
    echo "----------------------------------------------------\n";
    if ($all_passed) {
        echo "All updater tests passed successfully!\n";
        exit(0);
    } else {
        echo "Some tests FAILED. Please review the output above.\n";
        exit(1);
    }
}
