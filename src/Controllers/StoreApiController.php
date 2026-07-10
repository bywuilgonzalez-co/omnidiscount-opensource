<?php
namespace Drw\App\Controllers;

if (!defined('ABSPATH')) { exit; }

class StoreApiController {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {}

    public function register_hooks() {
        // Only register when Store API is available (WC 6.9+)
        add_action('woocommerce_blocks_loaded', [$this, 'register_store_api_integration']);

        // Mini-Cart (Bloques) promo badge — frontend-only, conditional on the
        // Mini-Cart block being present. See enqueue_minicart_blocks_assets()
        // and assets/js/drw-minicart-blocks.js for the full contract and its
        // "NO VERIFICADO EN NAVEGADOR REAL" caveats.
        add_action('wp_enqueue_scripts', [$this, 'enqueue_minicart_blocks_assets']);
    }

    public function register_store_api_integration() {
        if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }
        // Register cart extension data so discount info is available in the Block Cart response
        // Use the string identifier directly to avoid a hard dependency on WC's internal CartSchema class.
        woocommerce_store_api_register_endpoint_data([
            'endpoint'        => 'cart',
            'namespace'       => 'discount-rules-woo',
            'data_callback'   => [$this, 'get_cart_extension_data'],
            'schema_callback' => [$this, 'get_cart_extension_schema'],
            'schema_type'     => ARRAY_A,
        ]);

        // Apply discounts before Store API calculates totals
        add_action('woocommerce_store_api_cart_update_order_from_request', [$this, 'apply_discounts_to_block_cart'], 10, 2);
        add_filter('woocommerce_store_api_cart_errors', [$this, 'add_free_shipping_notice'], 10, 2);
    }

    /**
     * Apply plugin discounts when the Store API updates the cart (Block Cart/Checkout).
     * Mirrors the logic CartController uses via woocommerce_before_calculate_totals.
     */
    public function apply_discounts_to_block_cart($order, $request) {
        $cart = WC()->cart;
        if (!$cart) { return; }

        $engine = RulesEngine::instance();
        $engine->calculate_all_cart_discounts($cart);

        $item_prices = $engine->get_cached_cart_item_prices();
        if (!empty($item_prices)) {
            foreach ($cart->get_cart() as $key => $item) {
                if (isset($item_prices[$key])) {
                    $item['data']->set_price($item_prices[$key]);
                }
            }
        }

        $discounts = $engine->get_cached_cart_level_discounts();
        if (!empty($discounts['free_shipping'])) {
            WC()->session->set('drw_free_shipping', true);
        } else {
            WC()->session->set('drw_free_shipping', false);
        }

        $this->emit_promo_cart_notices($cart);
    }

    /**
     * Expose discount summary to the Block Cart/Checkout via Store API extension data.
     */
    public function get_cart_extension_data() {
        $cart   = WC()->cart;
        $engine = RulesEngine::instance();

        if (!$cart) {
            return ['discount_total' => 0, 'free_shipping' => false, 'applied_rules' => [], 'promos' => []];
        }

        $discounts    = $engine->get_cached_cart_level_discounts();
        $item_prices  = $engine->get_cached_cart_item_prices();
        $saved        = 0.0;

        foreach ($cart->get_cart() as $key => $item) {
            if (isset($item_prices[$key])) {
                $regular = (float)$item['data']->get_regular_price();
                $saved  += max(0, ($regular - $item_prices[$key]) * $item['quantity']);
            }
        }

        foreach (!empty($discounts['fees']) ? $discounts['fees'] : [] as $fee) {
            $saved += abs((float)$fee['amount']);
        }

        return [
            'discount_total' => wc_format_decimal($saved, 2),
            'free_shipping'  => !empty($discounts['free_shipping']),
            'applied_rules'  => [],
            'promos'         => $this->collect_promo_badges($cart),
        ];
    }

    /**
     * Schema definition for the Store API extension data.
     */
    public function get_cart_extension_schema() {
        return [
            'discount_total' => ['description' => 'Total discount applied by DRW rules', 'type' => 'string', 'context' => ['view', 'edit'], 'readonly' => true],
            'free_shipping'  => ['description' => 'Whether free shipping is granted by a rule', 'type' => 'boolean', 'context' => ['view', 'edit'], 'readonly' => true],
            'applied_rules'  => ['description' => 'List of applied rule IDs', 'type' => 'array', 'context' => ['view', 'edit'], 'readonly' => true, 'items' => ['type' => 'integer']],
            'promos'         => [
                'description' => 'Badge/message/progress data for active Vía B (automatic) promos, for Cart/Checkout Blocks display',
                'type'        => 'array',
                'context'     => ['view', 'edit'],
                'readonly'    => true,
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'promo_id' => ['type' => 'integer'],
                        'rule_id'  => ['type' => 'integer'],
                        'type'     => ['description' => 'Promo type, e.g. free_ship_threshold', 'type' => 'string'],
                        'title'    => ['type' => 'string'],
                        'message'  => ['description' => 'cart_message with {monto} resolved to the remaining amount, when applicable', 'type' => 'string'],
                        'applied'  => ['description' => 'Whether the promo is currently unlocked/matched for this cart', 'type' => 'boolean'],
                        'progress' => [
                            'description' => 'Progress bar data (free_ship_threshold and similar min_subtotal promos); null otherwise',
                            'type'        => ['object', 'null'],
                            'properties'  => [
                                'current'   => ['type' => 'string'],
                                'target'    => ['type' => 'string'],
                                'remaining' => ['type' => 'string'],
                                'percent'   => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Add a notice about free shipping in the Block Cart errors/notices channel.
     */
    public function add_free_shipping_notice($errors, $cart) {
        if (WC()->session && WC()->session->get('drw_free_shipping')) {
            // Notices for Block Cart use wc_add_notice, not this filter.
            // This filter is for error objects; skip silently.
        }
        return $errors;
    }

    /**
     * Unconditionally enqueue assets/js/drw-minicart-blocks.js — a vanilla,
     * no-build-step script that fetches wc/store/v1/cart directly (the same
     * Store API endpoint WooCommerce Blocks itself consumes) to read the
     * 'promos' extension data (get_cart_extension_data() above) and injects a
     * read-only badge into the WooCommerce Blocks Mini-Cart drawer, mirroring
     * the classic mini-cart badges from CartController::render_minicart_promos_html()
     * on a surface those hooks don't reach.
     *
     * No has_block('woocommerce/mini-cart') gate: verified live on a real
     * Storefront site that has_block() with no explicit $post argument only
     * inspects the CURRENT POST/PAGE CONTENT, so it returns false — and the
     * script never loads — when the Mini-Cart block is placed via
     * Appearance > Widgets (a classic widget area holding a block widget) or in
     * a block-theme template part, which is how this block is placed on the
     * large majority of real sites. The script itself is a no-op (it simply
     * never finds its drawer selector) on any page where the block isn't
     * rendered, so always enqueueing it trades a few KB of idle JS for actually
     * working on every real placement instead of only the narrow page-content
     * case.
     *
     * No 'wp-data' dependency: also verified live that this script cannot read
     * WooCommerce Blocks' cart data via wp.data.select('wc/store/cart') — the
     * frontend Mini-Cart bundle does not register its store on the shared
     * window.wp.data instance (window.wp.data.stores is empty there), so the
     * script fetches the Store API endpoint directly instead.
     */
    public function enqueue_minicart_blocks_assets() {
        // Honour the same features.show_minicart_promos toggle the classic
        // mini-cart badges respect (CartController::minicart_promos_enabled()).
        // The Blocks Mini-Cart drawer badge is the direct visual analog of the
        // classic mini-cart badge, so a merchant who switches off "promo badges
        // in the mini-cart" expects BOTH drawer surfaces to go quiet. This gates
        // only the drawer badge SCRIPT; the Store API `promos` extension data
        // (get_cart_extension_data) and the Cart/Checkout Block notices are left
        // untouched, matching that setting's documented mini-cart-only scope.
        if (class_exists('\\Drw\\App\\Models\\SettingsModel')
            && ! (bool) \Drw\App\Models\SettingsModel::get_setting('features.show_minicart_promos', true)) {
            return;
        }

        wp_enqueue_script(
            'drw-minicart-blocks',
            DRW_PLUGIN_URL . 'assets/js/drw-minicart-blocks.js',
            [],
            DRW_VERSION,
            true
        );

        // Reuses the sitewide stylesheet ShortcodeController::enqueue_public_assets()
        // already enqueues on every frontend request, which is where the
        // '.drw-minicart-blocks-promo*' rules live (see public-style.css) — no
        // separate stylesheet needed here.
    }

    /**
     * Build badge/progress/message data for every Vía B (automatic) promo
     * whose compiled wp_drw_rules row (source='promo') is currently active,
     * so Cart/Checkout Blocks can render a badge, a progress bar
     * (free_ship_threshold especially) and the merchant's cart_message.
     * Shared by get_cart_extension_data() (Store API schema payload) and
     * emit_promo_cart_notices() (Cart Notice) so both stay in sync from a
     * single source of truth.
     *
     * The logic now lives in PromoBadgeHelper::collect() so the classic
     * mini-cart widget (CartController) can render the exact same badges
     * without duplicating it; this thin wrapper keeps the existing internal
     * call sites unchanged. See PromoBadgeHelper::collect() for the full
     * behaviour contract, including the "applied" caveat.
     *
     * @param \WC_Cart $cart
     * @return array List of badge descriptors.
     */
    private function collect_promo_badges($cart) {
        return \Drw\App\Models\PromoBadgeHelper::collect($cart);
    }

    /**
     * Surface each matching Vía B promo's cart_message as a WooCommerce
     * notice while the Store API is processing the cart update. WooCommerce
     * Blocks picks up notices added with wc_add_notice() during this request
     * and renders them in Cart/Checkout Blocks — the same mechanism the
     * classic wc_print_notices() cart page uses, so no separate "Blocks"
     * rendering code is needed here.
     *
     * free_ship_threshold messages are shown as a progress nudge ONLY while
     * the threshold hasn't been reached yet (the {monto} placeholder is
     * meaningful there); once unlocked, WooCommerce's own $0 shipping rate
     * already communicates that. Every other promo type announces itself
     * once it is actually applied.
     *
     * wc_has_notice() guards against duplicate notices if this hook fires
     * more than once for the same request.
     *
     * @param \WC_Cart $cart
     */
    private function emit_promo_cart_notices($cart) {
        if (!function_exists('wc_add_notice') || !function_exists('wc_has_notice')) {
            return;
        }

        foreach ($this->collect_promo_badges($cart) as $badge) {
            $message = $badge['message'];
            if ('' === $message) {
                continue;
            }

            $is_threshold = (null !== $badge['progress']);
            $remaining    = $is_threshold ? (float)$badge['progress']['remaining'] : 0.0;

            $should_show = $is_threshold
                ? (!$badge['applied'] && $remaining > 0)
                : (bool)$badge['applied'];

            if (!$should_show) {
                continue;
            }

            if (!wc_has_notice($message, 'notice')) {
                wc_add_notice($message, 'notice');
            }
        }
    }
}
