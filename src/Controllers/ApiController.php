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
}
