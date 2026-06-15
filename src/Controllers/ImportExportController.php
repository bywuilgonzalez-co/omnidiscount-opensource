<?php
namespace Drw\App\Controllers;

if (!defined('ABSPATH')) { exit; }

class ImportExportController {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {}

    public function register_hooks() {
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('admin_post_drw_export_rules', [$this, 'handle_export']);
        add_action('admin_post_drw_import_rules', [$this, 'handle_import']);
        add_action('admin_menu', [$this, 'add_import_export_submenu']);
    }

    public function register_rest_routes() {
        register_rest_route('drw/v1', '/export', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_export'],
            'permission_callback' => function() { return current_user_can('manage_woocommerce'); },
        ]);

        register_rest_route('drw/v1', '/import', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rest_import'],
            'permission_callback' => function() { return current_user_can('manage_woocommerce'); },
        ]);
    }

    public function rest_export($request) {
        $rules = \Drw\App\Models\RuleModel::get_all_rules();
        // Strip internal DB-only fields
        $export = array_map(function($rule) {
            unset($rule['id'], $rule['deleted'], $rule['used_count'], $rule['created_at'], $rule['modified_at']);
            return $rule;
        }, $rules);
        return rest_ensure_response(['version' => DRW_VERSION, 'rules' => $export]);
    }

    public function rest_import($request) {
        $body  = $request->get_json_params();
        $rules = !empty($body['rules']) && is_array($body['rules']) ? $body['rules'] : [];

        if (empty($rules)) {
            return new \WP_Error('no_rules', __('No rules found in import data.', 'discount-rules-woo'), ['status' => 400]);
        }

        $imported = 0;
        foreach ($rules as $rule) {
            unset($rule['id']);
            $id = \Drw\App\Models\RuleModel::save_rule($rule);
            if ($id) { $imported++; }
        }

        return rest_ensure_response(['imported' => $imported]);
    }

    public function handle_export() {
        if (!current_user_can('manage_woocommerce')) { wp_die(esc_html__('Permission denied.', 'discount-rules-woo')); }
        check_admin_referer('drw_export_rules');

        $rules = \Drw\App\Models\RuleModel::get_all_rules();
        $export = array_map(function($rule) {
            unset($rule['id'], $rule['deleted'], $rule['used_count'], $rule['created_at'], $rule['modified_at']);
            return $rule;
        }, $rules);

        $json = wp_json_encode(['version' => DRW_VERSION, 'rules' => $export], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $filename = 'drw-rules-' . gmdate('Y-m-d') . '.json';

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public function handle_import() {
        if (!current_user_can('manage_woocommerce')) { wp_die(esc_html__('Permission denied.', 'discount-rules-woo')); }
        check_admin_referer('drw_import_rules');

        $imported = 0;
        $error    = '';

        if (!empty($_FILES['drw_import_file']['tmp_name'])) {
            $content = file_get_contents($_FILES['drw_import_file']['tmp_name']); // phpcs:ignore WordPress.WP.AlternativeFunctions
            $data    = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE || empty($data['rules'])) {
                $error = __('Invalid JSON file.', 'discount-rules-woo');
            } else {
                foreach ($data['rules'] as $rule) {
                    unset($rule['id']);
                    $id = \Drw\App\Models\RuleModel::save_rule($rule);
                    if ($id) { $imported++; }
                }
            }
        }

        $args = ['page' => 'drw-import-export', 'imported' => $imported];
        if ($error) { $args['error'] = urlencode($error); }
        wp_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public function add_import_export_submenu() {
        add_submenu_page(
            'drw-discount-rules',
            __('Import / Export', 'discount-rules-woo'),
            __('Import / Export', 'discount-rules-woo'),
            'manage_woocommerce',
            'drw-import-export',
            [$this, 'render_import_export_page']
        );
    }

    public function render_import_export_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Discount Rules – Import / Export', 'discount-rules-woo'); ?></h1>

            <?php if (!empty($_GET['imported'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php printf(esc_html__('%d rule(s) imported successfully.', 'discount-rules-woo'), (int)$_GET['imported']); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($_GET['error'])) : ?>
                <div class="notice notice-error"><p><?php echo esc_html(urldecode($_GET['error'])); ?></p></div>
            <?php endif; ?>

            <h2><?php esc_html_e('Export Rules', 'discount-rules-woo'); ?></h2>
            <p><?php esc_html_e('Download all discount rules as a JSON file.', 'discount-rules-woo'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('drw_export_rules'); ?>
                <input type="hidden" name="action" value="drw_export_rules">
                <?php submit_button(__('Download JSON', 'discount-rules-woo'), 'secondary'); ?>
            </form>

            <hr>
            <h2><?php esc_html_e('Import Rules', 'discount-rules-woo'); ?></h2>
            <p><?php esc_html_e('Upload a JSON file exported from another store. Rules will be appended (existing rules are not deleted).', 'discount-rules-woo'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('drw_import_rules'); ?>
                <input type="hidden" name="action" value="drw_import_rules">
                <input type="file" name="drw_import_file" accept=".json" required>
                <?php submit_button(__('Import Rules', 'discount-rules-woo')); ?>
            </form>
        </div>
        <?php
    }
}
