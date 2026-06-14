<?php

namespace Drw\App\Controllers;

use Drw\App\Models\RuleModel;

if (!defined('ABSPATH')) {
    exit;
}

class ApiController
{
    private static $instance = null;

    /**
     * Singleton instance.
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Register REST API routes.
     */
    public function register_hooks()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register endpoints.
     */
    public function register_routes()
    {
        $namespace = 'drw/v1';

        // Get all rules & Create rule
        register_rest_route($namespace, '/rules', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_rules'],
                'permission_callback' => [$this, 'check_permission'],
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'save_rule'],
                'permission_callback' => [$this, 'check_permission'],
            ]
        ]);

        // Get single, update, and delete rule
        register_rest_route($namespace, '/rules/(?P<id>\d+)', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_rule'],
                'permission_callback' => [$this, 'check_permission'],
                'args' => [
                    'id' => [
                        'validate_callback' => function($param, $request, $key) {
                            return is_numeric($param);
                        }
                    ],
                ],
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'delete_rule'],
                'permission_callback' => [$this, 'check_permission'],
                'args' => [
                    'id' => [
                        'validate_callback' => function($param, $request, $key) {
                            return is_numeric($param);
                        }
                    ],
                ],
            ]
        ]);

        // Search products for async admin selectors.
        register_rest_route($namespace, '/products', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'search_products'],
                'permission_callback' => [$this, 'check_permission'],
                'args' => [
                    'search' => [
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'include' => [
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'page' => [
                        'default'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                    'per_page' => [
                        'default'           => 20,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Permission check: Require WooCommerce management capabilities.
     */
    public function check_permission()
    {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    /**
     * GET /drw/v1/rules
     */
    public function get_rules($request)
    {
        $rules = RuleModel::get_all_rules();
        return new \WP_REST_Response($rules, 200);
    }

    /**
     * GET /drw/v1/products
     */
    public function search_products($request)
    {
        $search = sanitize_text_field((string)$request->get_param('search'));
        $include = $this->parse_id_list($request->get_param('include'));
        $page = max(1, absint($request->get_param('page')));
        $per_page = min(50, max(1, absint($request->get_param('per_page'))));

        $query_args = [
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'posts_per_page'         => $per_page,
            'paged'                  => $page,
            'orderby'                => 'title',
            'order'                  => 'ASC',
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];

        if ($search !== '') {
            $query_args['s'] = $search;
        }

        if (!empty($include)) {
            $query_args['post__in'] = $include;
            $query_args['orderby'] = 'post__in';
            $query_args['posts_per_page'] = min(50, count($include));
            unset($query_args['s']);
        }

        $product_ids = get_posts($query_args);
        $items = [];

        foreach ($product_ids as $product_id) {
            $product_id = is_object($product_id) && isset($product_id->ID) ? $product_id->ID : $product_id;
            $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
            if (!$product) {
                continue;
            }

            $sku = (string)$product->get_sku();
            $items[] = [
                'id'   => (int)$product->get_id(),
                'name' => $product->get_name(),
                'sku'  => $sku,
                'type' => $product->get_type(),
                'text' => $sku !== '' ? sprintf('%s (%s)', $product->get_name(), $sku) : $product->get_name(),
            ];
        }

        return new \WP_REST_Response([
            'items' => $items,
            'page'  => $page,
        ], 200);
    }

    /**
     * GET /drw/v1/rules/<id>
     */
    public function get_rule($request)
    {
        $id = (int)$request['id'];
        $rule = RuleModel::get_rule($id);

        if (!$rule) {
            return new \WP_REST_Response(['message' => __('Rule not found', 'discount-rules-woo')], 404);
        }

        return new \WP_REST_Response($rule, 200);
    }

    /**
     * POST /drw/v1/rules
     */
    public function save_rule($request)
    {
        $params = $request->get_json_params();
        if (empty($params)) {
            $params = $request->get_body_params();
        }

        if (empty($params['title'])) {
            return new \WP_REST_Response(['message' => __('Title is required', 'discount-rules-woo')], 400);
        }

        $id = RuleModel::save_rule($params);
        $saved_rule = RuleModel::get_rule($id);

        // Clear engine cache
        \Drw\App\Controllers\RulesEngine::instance()->clear_cache();

        return new \WP_REST_Response($saved_rule, 200);
    }

    /**
     * DELETE /drw/v1/rules/<id>
     */
    public function delete_rule($request)
    {
        $id = (int)$request['id'];
        $rule = RuleModel::get_rule($id);

        if (!$rule) {
            return new \WP_REST_Response(['message' => __('Rule not found', 'discount-rules-woo')], 404);
        }

        RuleModel::delete_rule($id);
        
        // Clear engine cache
        \Drw\App\Controllers\RulesEngine::instance()->clear_cache();

        return new \WP_REST_Response(['success' => true, 'message' => __('Rule deleted', 'discount-rules-woo')], 200);
    }

    /**
     * Normalize comma-separated or array IDs from REST query params.
     */
    private function parse_id_list($value)
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $item) {
            $id = (int)$item;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
