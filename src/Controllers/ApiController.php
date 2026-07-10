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

        // POST /drw/v1/rules/<id>/sandbox – admin-only preview activation for a
        // manually-created rule. Equivalent to PromosController::activate_sandbox()
        // (same cookie mechanism, same TTL, same signing helper) but resolves the
        // id DIRECTLY against RuleModel::get_rule() — there is no promo row to go
        // through, because a manual rule never had one to begin with. See
        // PromoBridgeController::build_sandbox_cookie_value() (issuance, shared
        // unchanged) and PromoBridgeController::resolve_manual_rule_sandbox()
        // (verification, read by CartController via get_sandboxed_rule_for_current_user()).
        register_rest_route($namespace, '/rules/(?P<id>\d+)/sandbox', [
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'activate_rule_sandbox'],
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
     * POST /drw/v1/rules/<id>/sandbox
     *
     * "Sandbox mode" for a manually-created rule: lets the CURRENT admin
     * preview a disabled rule end-to-end in their own cart, without flipping
     * the rule's `enabled` column and therefore WITHOUT ever exposing it to
     * real customers. Equivalent to PromosController::activate_sandbox() for
     * promos — same signed-cookie mechanism, same 30-minute TTL, same
     * build_sandbox_cookie_value() helper — but scoped to a rule id and
     * stored under a SEPARATE cookie (PromoBridgeController::SANDBOX_RULE_COOKIE_NAME)
     * so it can never collide with a promo sandbox cookie's id space.
     *
     * The cookie scopes the override to:
     *   - this one rule id,
     *   - this one WP user id (re-checked against get_current_user_id() on
     *     every read, so a copied/leaked cookie is inert for anyone else),
     *   - a server-side expiry embedded in the signed payload.
     *
     * The rule row itself is never written to by this endpoint. The read
     * side (PromoBridgeController::get_sandboxed_rule_for_current_user(),
     * consumed by CartController) is what actually makes the rule *behave*
     * as enabled, and only for this cookie's owner.
     *
     * Unlike promos (Vía A code-based promos are out of scope for sandbox —
     * see PromoTypeRegistry::needs_code()), manually-created rules have no
     * equivalent "needs a code" concept: every wp_drw_rules row is matched
     * and applied automatically by RulesEngine, never redeemed via a typed
     * code. There is therefore no type-based restriction to mirror here.
     * The `exclusive` flag only changes how a rule interacts with OTHER
     * rules once it is genuinely live (RulesEngine short-circuits further
     * matching); it does not affect whether this rule itself can be
     * sandboxed, so it is intentionally not checked here either.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function activate_rule_sandbox($request)
    {
        $id = (int)$request['id'];
        $rule = RuleModel::get_rule($id);

        if (!$rule) {
            return new \WP_REST_Response(['message' => __('Rule not found', 'discount-rules-woo')], 404);
        }

        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            // Belt-and-braces: check_permission() already requires a
            // capability, which implies a logged-in user, but a signed
            // cookie with no owner would be meaningless.
            return new \WP_REST_Response(['message' => __('Debes iniciar sesión para usar el modo sandbox.', 'discount-rules-woo')], 401);
        }

        $expires_at = time() + PromoBridgeController::SANDBOX_TTL;
        $token      = PromoBridgeController::build_sandbox_cookie_value($id, $user_id, $expires_at);

        $this->set_rule_sandbox_cookie($token, $expires_at);

        return new \WP_REST_Response([
            'success'   => true,
            'ruleId'    => $id,
            'expiresAt' => $expires_at,
            'message'   => __('Modo sandbox activado solo para tu sesión de administrador. Esta regla NO se ha publicado a los clientes.', 'discount-rules-woo'),
        ], 200);
    }

    /**
     * Write the signed rule-sandbox token as an HttpOnly, SameSite=Strict
     * cookie scoped to the site's own cookie path/domain. Mirrors
     * PromosController::set_sandbox_cookie() exactly, only the cookie name
     * differs (PromoBridgeController::SANDBOX_RULE_COOKIE_NAME).
     *
     * @param string $token      Signed "{ruleId}:{userId}:{expiresAt}:{signature}" value.
     * @param int    $expires_at Unix timestamp the cookie (and the signed payload) expire at.
     * @return void
     */
    private function set_rule_sandbox_cookie($token, $expires_at)
    {
        $path   = defined('COOKIEPATH') ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
        $secure = function_exists('is_ssl') ? is_ssl() : false;

        if (PHP_VERSION_ID >= 70300) {
            setcookie(
                PromoBridgeController::SANDBOX_RULE_COOKIE_NAME,
                $token,
                [
                    'expires'  => $expires_at,
                    'path'     => $path,
                    'domain'   => $domain,
                    'secure'   => $secure,
                    'httponly' => true,
                    'samesite' => 'Strict',
                ]
            );
        } else {
            // PHP < 7.3 has no SameSite param on setcookie(); append it to the
            // path the same way WordPress core does for its own auth cookies.
            setcookie(
                PromoBridgeController::SANDBOX_RULE_COOKIE_NAME,
                $token,
                $expires_at,
                $path . '; samesite=Strict',
                $domain,
                $secure,
                true
            );
        }
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
