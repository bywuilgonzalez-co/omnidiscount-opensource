<?php
/**
 * Focused smoke test for the admin product search REST contract.
 */

define('ABSPATH', dirname(__DIR__) . '/');

global $registered_routes, $last_get_posts_args;
$registered_routes = [];
$last_get_posts_args = null;

class WP_REST_Server {
    const READABLE = 'GET';
    const CREATABLE = 'POST';
    const DELETABLE = 'DELETE';
}

class WP_REST_Response {
    public $data;
    public $status;

    public function __construct($data, $status = 200) {
        $this->data = $data;
        $this->status = $status;
    }

    public function get_data() {
        return $this->data;
    }
}

class Drw_Test_Request implements ArrayAccess {
    private $params;

    public function __construct($params = []) {
        $this->params = $params;
    }

    public function get_param($key) {
        return $this->params[$key] ?? null;
    }

    public function offsetExists($offset): bool {
        return isset($this->params[$offset]);
    }

    public function offsetGet($offset): mixed {
        return $this->params[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void {
        $this->params[$offset] = $value;
    }

    public function offsetUnset($offset): void {
        unset($this->params[$offset]);
    }
}

class Drw_Test_Product {
    private $id;

    public function __construct($id) {
        $this->id = (int) $id;
    }

    public function get_id() {
        return $this->id;
    }

    public function get_name() {
        return 'Product ' . $this->id;
    }

    public function get_sku() {
        return 'SKU-' . $this->id;
    }

    public function get_type() {
        return 'simple';
    }
}

function register_rest_route($namespace, $route, $args) {
    global $registered_routes;
    $registered_routes[$namespace . $route] = $args;
}

function current_user_can($capability) {
    return $capability === 'manage_woocommerce';
}

function __($text, $domain = null) {
    return $text;
}

function sanitize_text_field($value) {
    return trim(strip_tags((string) $value));
}

function absint($value) {
    return max(0, abs((int) $value));
}

function get_posts($args) {
    global $last_get_posts_args;
    $last_get_posts_args = $args;

    $ids = $args['post__in'] ?? [101, 102];
    return array_map(function ($id) {
        return (object) ['ID' => (int) $id];
    }, $ids);
}

function wc_get_product($id) {
    return new Drw_Test_Product($id);
}

function assert_true($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

require_once dirname(__DIR__) . '/src/Controllers/ApiController.php';

$controller = Drw\App\Controllers\ApiController::instance();
$controller->register_routes();

assert_true(isset($registered_routes['drw/v1/products']), 'GET /drw/v1/products route should be registered.');

$response = $controller->search_products(new Drw_Test_Request([
    'search' => '  Shirt <b>Sale</b> ',
    'per_page' => 100,
]));

$data = $response->get_data();
assert_true($response->status === 200, 'Product search should return HTTP 200.');
assert_true($last_get_posts_args['posts_per_page'] === 50, 'per_page should be capped at 50.');
assert_true($last_get_posts_args['s'] === 'Shirt Sale', 'Search text should be sanitized.');

$response = $controller->search_products(new Drw_Test_Request([
    'include' => '42, 7, bad, -4',
    'per_page' => 100,
]));

$data = $response->get_data();
assert_true(count($data['items']) === 2, 'Only positive include IDs should be returned.');
assert_true($data['items'][0]['id'] === 42, 'Included product IDs should preserve requested order.');
assert_true($data['items'][0]['name'] === 'Product 42', 'Each product item should expose its name.');
assert_true($data['items'][0]['sku'] === 'SKU-42', 'Each product item should expose its SKU.');

echo "Product search API contract OK\n";
