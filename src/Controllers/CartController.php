<?php

namespace Drw\App\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

class CartController
{
    private static $instance = null;
    private $is_recalculating = false;
    private $is_adding_to_cart = false;

    /**
     * IDENTITY DOCUMENT / FIRST-PURCHASE VERIFICATION -- id used to register
     * the "Documento de identidad" field with WooCommerce Blocks' Additional
     * Checkout Fields API (see register_identity_document_block_field()).
     * Field ids for that API MUST be namespaced as "namespace/name" --
     * verified directly against this site's installed WooCommerce core
     * source (src/Blocks/Domain/Services/CheckoutFields.php
     * validate_options(): "A checkout field id must consist of
     * namespace/name").
     */
    const IDENTITY_DOCUMENT_FIELD_ID = 'drw/documento-identidad';

    /**
     * IDENTITY DOCUMENT / FIRST-PURCHASE VERIFICATION -- visibility gate
     * caching. See should_show_identity_document_field()/welcome_promo_exists()
     * below: the transient key holding the cached "does at least one active
     * wp_drw_promos row of type='welcome' exist" result, and its TTL. A
     * plain integer literal (not e.g. `10 * MINUTE_IN_SECONDS`) so this file
     * keeps loading standalone in the tests/test-*.php suite, which does not
     * bootstrap WordPress core constants.
     */
    const WELCOME_PROMO_EXISTS_TRANSIENT = 'drw_welcome_promo_exists';
    const WELCOME_PROMO_EXISTS_TTL = 600; // 10 minutes.

    /**
     * SANDBOX MODE — per-request cache for PromoBridgeController's cookie
     * lookup (see get_sandbox_rule() below). Resolved at most once per
     * request/singleton lifetime, exactly like RulesEngine's own
     * $cached_rules; never shared across requests/users.
     */
    private $sandbox_rule_resolved = false;
    private $sandbox_rule = null;

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
     * Register hooks for cart and checkout pricing adjustments.
     */
    public function register_hooks()
    {
        // Line item pricing recalculation
        add_action('woocommerce_before_calculate_totals', [$this, 'recalculate_cart_item_prices'], 20, 1);

        // Cart-wide fees (subtotal based discounts)
        add_action('woocommerce_cart_calculate_fees', [$this, 'apply_cart_wide_fees'], 20, 1);

        // Save order metadata on checkout
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'save_line_item_metadata'], 20, 4);

        // Count real promo redemptions once an order is paid. Both statuses point
        // to the same handler; the per-order _drw_promos_counted flag guarantees a
        // promo is only ever counted once, no matter which/how many times it fires.
        // track_promo_redemptions() also confirms any 'reserved' redemption rows
        // for this order (see reserve_promo_usage() below) into 'confirmed'.
        add_action('woocommerce_order_status_processing', [$this, 'track_promo_redemptions'], 20, 2);
        add_action('woocommerce_order_status_completed', [$this, 'track_promo_redemptions'], 20, 2);

        // Reserve per-customer promo/rule usage the moment an order is created,
        // closing the checkout-time race a customer could otherwise exploit by
        // opening two tabs (see RuleModel::try_reserve_usage()).
        //
        // TWO separate hooks are required -- verified directly against the
        // WooCommerce core source shipped with this site
        // (wp-content/plugins/woocommerce/includes/class-wc-checkout.php and
        // .../src/StoreApi/Routes/V1/Checkout.php + CheckoutOrder.php), because
        // an initial assumption that woocommerce_checkout_order_processed is a
        // cross-flow "compatibility bridge" that also fires from the Store API
        // turned out to be FALSE:
        //   - Classic WC_Checkout::process_checkout() fires
        //     'woocommerce_checkout_order_processed' ($order_id, $posted_data,
        //     $order) -- includes/class-wc-checkout.php line ~1363.
        //   - The Store API's Checkout route (used by Cart & Checkout Blocks)
        //     and CheckoutOrder route (pay-for-order) do NOT fire that action at
        //     all. They fire a DIFFERENT, dedicated action instead:
        //     'woocommerce_store_api_checkout_order_processed' ($order only) --
        //     its own docblock literally says "This is similar to existing core
        //     hook woocommerce_checkout_order_processed. We're using a new
        //     action". Hooking only the classic action would have silently never
        //     fired reservation on this site at all, since this checkout is
        //     Blocks-only (verified live: has_block('woocommerce/checkout')
        //     === true, no [woocommerce_checkout] shortcode anywhere) -- the
        //     exact same class of bug as the earlier Mini-Cart Blocks issue.
        // reserve_promo_usage() itself is written against the classic 3-arg
        // shape; reserve_promo_usage_store_api() adapts the Store API's 1-arg
        // ($order only) shape to it so both flows share one implementation.
        add_action('woocommerce_checkout_order_processed', [$this, 'reserve_promo_usage'], 20, 3);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'reserve_promo_usage_store_api'], 20, 1);

        // Release a reservation the moment an order is definitively NOT going to
        // be paid, same dual-hook style as the counting hooks above.
        add_action('woocommerce_order_status_cancelled', [$this, 'release_reserved_usage'], 20, 2);
        add_action('woocommerce_order_status_failed', [$this, 'release_reserved_usage'], 20, 2);

        // Safety net: reap reservations left dangling by an order that never
        // reaches a terminal status at all (e.g. abandoned pending payment).
        // The 'daily' schedule itself is registered in Router::run_migrations(),
        // next to table creation.
        add_action('drw_release_stale_promo_reservations', [$this, 'release_stale_promo_reservations']);

        // Shipping modifications
        add_filter('woocommerce_package_rates', [$this, 'modify_shipping_package_rates'], 20, 2);

        // Coupon matching
        add_filter('woocommerce_get_shop_coupon_data', [$this, 'get_shop_coupon_data'], 20, 2);

        // Layout formatting filters
        add_filter('woocommerce_cart_item_price', [$this, 'format_cart_item_price'], 20, 3);
        add_filter('woocommerce_cart_item_subtotal', [$this, 'format_cart_item_subtotal'], 20, 3);
        add_filter('woocommerce_cart_totals_order_total_html', [$this, 'format_cart_totals_order_total_html'], 20, 1);
        add_filter('woocommerce_get_formatted_order_total', [$this, 'format_order_total'], 20, 4);
        add_filter('woocommerce_order_formatted_line_subtotal', [$this, 'format_order_line_subtotal'], 20, 3);
        add_action('woocommerce_admin_order_totals_after_total', [$this, 'display_admin_order_totals_after_total'], 20, 1);

        // Classic mini-cart promos. woocommerce_widget_shopping_cart_before_buttons
        // is a core hook fired inside WooCommerce's templates/cart/mini-cart.php
        // (the markup woocommerce_mini_cart() renders for the Cart widget and the
        // [woocommerce_cart] classic mini-cart), just before the buttons row. The
        // add_to_cart_fragments filter keeps that output in sync on AJAX refresh.
        add_action('woocommerce_widget_shopping_cart_before_buttons', [$this, 'render_minicart_promos'], 20);
        add_filter('woocommerce_add_to_cart_fragments', [$this, 'add_minicart_promos_fragment'], 20, 1);

        // IDENTITY DOCUMENT / FIRST-PURCHASE VERIFICATION (welcome-coupon
        // anti-fraud extension) -- see the docblocks on the methods below
        // for the full design. Three concerns, three hooks:
        //   1) Render the field on CLASSIC checkout (shortcode-based).
        //   2) Render (+ require) the field on Cart & Checkout BLOCKS
        //      checkout, via WooCommerce's own "Additional Checkout Fields"
        //      API -- 'woocommerce_checkout_fields' alone is a classic-only
        //      filter and is NEVER consulted by the Blocks checkout UI/Store
        //      API validation (verified directly against this site's
        //      installed WooCommerce 10.9.4 core: the Blocks checkout route
        //      builds its own field set from CheckoutFields::$additional_fields,
        //      not from WC_Checkout::get_checkout_fields()). This is a
        //      DEVIATION from the plan document's literal wording (which only
        //      names 'woocommerce_checkout_fields'), flagged explicitly here
        //      because this site's checkout is Blocks-only (already
        //      confirmed live earlier this session) -- relying on the
        //      classic filter alone would silently never show the field at
        //      all on the actual live checkout.
        //   3) Persist the posted value onto '_billing_documento_identidad'
        //      for the Store API/Blocks flow. The CLASSIC flow needs NO
        //      extra save hook at all: WC_Checkout::create_order() already
        //      auto-persists any posted 'billing_*' checkout field with no
        //      matching WC_Order::set_*() method as order meta prefixed with
        //      an underscore (includes/class-wc-checkout.php ~line 429-436,
        //      verified directly against this site's core) -- i.e. our
        //      'billing_documento_identidad' field is saved to
        //      '_billing_documento_identidad' automatically, for free. The
        //      Blocks Additional Checkout Fields API does NOT do the
        //      equivalent: a field registered at location='address' is
        //      auto-persisted under its OWN namespaced meta key
        //      ('_wc_billing/' . field id), never under a plain
        //      '_billing_*' key, so save_identity_document_store_api() below
        //      reads the raw value straight off the Store API request (the
        //      exact same $request->get_param('billing_address')[$field_id]
        //      shape WooCommerce's own validate_callback() reads it from --
        //      see that method for the verified reference) and copies it
        //      into the SAME '_billing_documento_identidad' meta key the
        //      classic flow uses, so every downstream reader (this class'
        //      own enforce_first_purchase_welcome_coupon() below,
        //      Conditions/PurchaseHistory.php-style HPOS queries) only ever
        //      has ONE meta key to look at regardless of checkout flow.
        add_filter('woocommerce_checkout_fields', [$this, 'add_identity_document_checkout_field']);
        add_action('init', [$this, 'register_identity_document_block_field']);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'save_identity_document_store_api'], 20, 2);
    }

    /**
     * Classic checkout: add the required "Documento de identidad" text field
     * to the billing fieldset via the standard 'woocommerce_checkout_fields'
     * filter. See register_hooks() for why this alone is not sufficient for
     * the Blocks checkout this site actually runs.
     *
     * @param array $fields
     * @return array
     */
    public function add_identity_document_checkout_field($fields)
    {
        if (!$this->should_show_identity_document_field()) {
            return $fields;
        }

        if (!isset($fields['billing']) || !is_array($fields['billing'])) {
            $fields['billing'] = [];
        }

        $fields['billing']['billing_documento_identidad'] = [
            'type'        => 'text',
            'label'       => __('Documento de identidad', 'discount-rules-woo'),
            'placeholder' => __('Número de documento', 'discount-rules-woo'),
            'required'    => true,
            'class'       => ['form-row-wide'],
            // Placed after the standard billing fields (email is priority
            // 110 in WooCommerce core) so it doesn't reshuffle the existing
            // billing form layout.
            'priority'    => 120,
        ];

        return $fields;
    }

    /**
     * Cart & Checkout Blocks: register the same "Documento de identidad"
     * field via WooCommerce's Additional Checkout Fields API
     * (woocommerce_register_additional_checkout_field(), core since WC
     * 8.6 -- confirmed present on this site's installed WooCommerce 10.9.4).
     * This is what actually makes the field appear (and be enforced as
     * required by Store API validation) on the Blocks checkout; see
     * register_hooks() for the full rationale.
     *
     * Hooked to 'init' (registered from register_hooks(), itself called on
     * 'plugins_loaded' -- see discount-rules-woo.php / Router::init() --
     * i.e. safely before 'init' fires). The function itself additionally
     * defers to 'woocommerce_blocks_loaded' internally if WooCommerce Blocks
     * hasn't finished loading yet, so this is also safe regardless of
     * plugin load order.
     *
     * location='address' means this field is offered on BOTH the billing
     * AND shipping address forms (the Additional Checkout Fields API has no
     * built-in "billing only" location -- verified against
     * CheckoutFields::validate_options()/$fields_locations, which only
     * recognises 'address'|'contact'|'order'). Flagged as a known,
     * accepted trade-off vs. the plan's "billing fieldset" wording: most
     * storefronts ship to the billing address by default, and worst case a
     * shopper types their document twice rather than the field being
     * missing/unenforced on Blocks checkout altogether.
     *
     * Wrapped in try/catch: this function throws \Exception on registration
     * failure (e.g. a duplicate id from another plugin) -- that must never
     * fatal the whole 'init' hook chain for this store.
     */
    public function register_identity_document_block_field()
    {
        if (!function_exists('woocommerce_register_additional_checkout_field')) {
            // WooCommerce (or its bundled Blocks package) isn't loaded, or
            // predates the Additional Checkout Fields API -- the classic
            // filter above still covers non-Blocks checkouts.
            return;
        }

        // Hooked to 'init', which fires on EVERY request sitewide -- see
        // should_show_identity_document_field()'s docblock for why this
        // check is safe to run unconditionally here.
        if (!$this->should_show_identity_document_field()) {
            return;
        }

        try {
            woocommerce_register_additional_checkout_field([
                'id'       => self::IDENTITY_DOCUMENT_FIELD_ID,
                'label'    => __('Documento de identidad', 'discount-rules-woo'),
                'location' => 'address',
                'type'     => 'text',
                'required' => true,
            ]);
        } catch (\Throwable $e) {
            error_log('[discount-rules-woo] Identity document checkout field registration failed: ' . $e->getMessage());
        }
    }

    /**
     * Cart & Checkout Blocks / Store API: copy the posted "Documento de
     * identidad" value into the SAME '_billing_documento_identidad' order
     * meta key the classic checkout flow auto-persists (see register_hooks()
     * docblock). Deliberately reads the raw value straight off the request
     * rather than trusting the Additional Checkout Fields API's own
     * namespaced auto-persist meta key -- see that docblock for why.
     *
     * Hooked to 'woocommerce_store_api_checkout_update_order_from_request',
     * fired from BOTH the main Checkout route's update_order_from_request()
     * (POST /checkout, and the debounced PATCH updates the Blocks checkout
     * UI sends as the shopper fills the form) and the pay-for-order
     * CheckoutOrder route -- verified directly against this site's installed
     * WooCommerce core (src/StoreApi/Utilities/CheckoutTrait.php /
     * src/StoreApi/Routes/V1/Checkout.php), firing BEFORE
     * 'woocommerce_store_api_checkout_order_processed' (which
     * reserve_promo_usage_store_api() below is hooked to), so the meta is
     * guaranteed to already be on the order by the time
     * enforce_first_purchase_welcome_coupon() reads it back.
     *
     * A no-op (never overwrites existing meta with an empty value) on the
     * intermediate debounced PATCH requests that don't include a full
     * billing_address payload -- safe, since the final POST /checkout the
     * Blocks checkout UI sends always includes the complete address object.
     *
     * @param \WC_Order          $order
     * @param \WP_REST_Request   $request
     */
    public function save_identity_document_store_api($order, $request)
    {
        if (!($order instanceof \WC_Order) || !is_object($request) || !method_exists($request, 'get_param')) {
            return;
        }

        $billing = $request->get_param('billing_address');
        $billing = is_array($billing) ? $billing : [];

        $value = isset($billing[self::IDENTITY_DOCUMENT_FIELD_ID]) ? (string)$billing[self::IDENTITY_DOCUMENT_FIELD_ID] : '';
        $value = function_exists('sanitize_text_field') ? sanitize_text_field(function_exists('wp_unslash') ? wp_unslash($value) : $value) : trim($value);

        if ('' === $value) {
            return;
        }

        $order->update_meta_data('_billing_documento_identidad', $value);
        $order->save();
    }

    /**
     * IDENTITY DOCUMENT / FIRST-PURCHASE VERIFICATION -- visibility gate.
     *
     * Whether the "Documento de identidad" checkout field should be shown
     * (classic) / registered (Blocks) at all for THIS site. Before this
     * method existed, the field was added UNCONDITIONALLY by both
     * add_identity_document_checkout_field() and
     * register_identity_document_block_field(), forcing every merchant
     * running this plugin -- including ones who never enable the popup and
     * never author a 'welcome'-type promo -- to make every shopper fill in
     * an extra required field for a feature they don't use.
     *
     * True when EITHER:
     *   (a) the email-capture popup is enabled (SettingsModel
     *       'popup.enabled') -- the popup mints 'welcome'-type coupons, so
     *       enforce_first_purchase_welcome_coupon() can actually fire and
     *       needs the document to cross-check; OR
     *   (b) at least one wp_drw_promos row of type='welcome' exists, is
     *       active (active = 1) and not soft-deleted (deleted_at IS NULL)
     *       -- a merchant can author a 'welcome' promo by hand in the
     *       Promos wizard without ever touching the popup toggle (see
     *       order_has_welcome_coupon(), which already treats both origins
     *       identically).
     *
     * Deliberately does NOT gate save_identity_document_store_api() or
     * WC core's own classic 'billing_*' -> '_billing_*' auto-persist path:
     * both are harmless no-ops when the field was never rendered (there is
     * nothing posted to save), and leaving them unconditional avoids a
     * second visibility check to keep in sync with this one. Also does NOT
     * touch enforce_first_purchase_welcome_coupon() itself -- that method
     * already no-ops via order_has_welcome_coupon() for any order that
     * doesn't actually carry a welcome coupon, entirely independent of
     * whether the checkout field was shown for that session.
     *
     * Performance: register_identity_document_block_field() is hooked to
     * 'init', which fires on EVERY request sitewide (not just checkout),
     * so this must stay cheap in the common case:
     *   - (a) is checked FIRST and short-circuits before ever touching the
     *     'wp_drw_promos' table. SettingsModel::get_setting() is itself
     *     transient-cached for 12h (SettingsModel::CACHE_TTL), so the
     *     common "popup enabled" case costs nothing beyond that existing
     *     cache -- no extra DB round trip is added by this method.
     *   - (b) is only ever reached once the popup is confirmed OFF, and is
     *     itself wrapped in its own short-lived transient by
     *     welcome_promo_exists() below, so a popup-disabled site with no
     *     welcome promos still doesn't hit the database on every page load
     *     sitewide -- only once per WELCOME_PROMO_EXISTS_TTL window.
     *
     * @return bool
     */
    private function should_show_identity_document_field()
    {
        if (\Drw\App\Models\SettingsModel::get_setting('popup.enabled', false)) {
            return true;
        }

        return $this->welcome_promo_exists();
    }

    /**
     * (b) of should_show_identity_document_field() above, isolated so it
     * carries its own short-lived transient cache (WELCOME_PROMO_EXISTS_TTL
     * -- 10 minutes) separate from SettingsModel's 12h settings cache.
     *
     * Not invalidated on write (a welcome promo being created/activated/
     * deleted does not proactively clear this transient) -- per the task's
     * explicit call: a brief window (up to 10 minutes) where the field
     * appears or disappears a little late is an acceptable trade-off for
     * something this low-stakes, and avoids adding a second code path
     * (cache invalidation on every promo save) to keep in sync.
     *
     * The query itself is an EXISTS-style 'SELECT 1 ... LIMIT 1' (same
     * pattern as order_already_holds_reservation() elsewhere in this
     * class) that rides the 'active_idx' (active, deleted_at) index
     * already defined on wp_drw_promos (see Database.php) -- no full scan,
     * no row hydration, just a single index lookup capped at one match.
     *
     * @return bool
     */
    private function welcome_promo_exists()
    {
        $cached = get_transient(self::WELCOME_PROMO_EXISTS_TRANSIENT);
        if (false !== $cached) {
            return '1' === $cached;
        }

        global $wpdb;
        $promos_table = $wpdb->prefix . 'drw_promos';

        $exists = (bool)$wpdb->get_var(
            "SELECT 1 FROM $promos_table WHERE type = 'welcome' AND active = 1 AND deleted_at IS NULL LIMIT 1"
        );

        set_transient(self::WELCOME_PROMO_EXISTS_TRANSIENT, $exists ? '1' : '0', self::WELCOME_PROMO_EXISTS_TTL);

        return $exists;
    }

    /**
     * Echo the classic mini-cart promo badges inside the Cart widget /
     * [woocommerce_cart] mini-cart (woocommerce_widget_shopping_cart_before_buttons).
     *
     * The whole widget body is already the AJAX-refreshed
     * div.widget_shopping_cart_content fragment, so this output stays current on
     * add-to-cart without any extra wiring; add_minicart_promos_fragment() below
     * is a belt-and-suspenders refresh of the promos node on its own.
     *
     * Prints nothing (zero DOM footprint) when the toggle is off or when there
     * is no applicable promo — render_minicart_promos_html() returns '' there.
     */
    public function render_minicart_promos()
    {
        if (!$this->minicart_promos_enabled()) {
            return;
        }

        $cart = (function_exists('WC') && WC()) ? WC()->cart : null;

        // render_minicart_promos_html() fully escapes every dynamic value it
        // interpolates (esc_html on copy, esc_attr on the state class); the rest
        // is a static, hard-coded markup skeleton, so the assembled string is
        // safe to echo as-is.
        echo $this->render_minicart_promos_html($cart); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Refresh the mini-cart promos node on AJAX add-to-cart.
     *
     * WooCommerce replaces each fragment's matched element via
     * jQuery(selector).replaceWith(html); the rendered HTML is itself wrapped in
     * the .drw-minicart-promos node so the selector keeps matching across
     * refreshes, and an empty string cleanly removes a stale node (keeping the
     * zero-DOM-footprint promise when no promo applies). Uses the exact same
     * render_minicart_promos_html() as the direct hook so the two never drift.
     *
     * @param array $fragments Selector => HTML map WooCommerce swaps into the DOM.
     * @return array
     */
    public function add_minicart_promos_fragment($fragments)
    {
        if (!$this->minicart_promos_enabled()) {
            return $fragments;
        }

        $cart = (function_exists('WC') && WC()) ? WC()->cart : null;

        $fragments['.drw-minicart-promos'] = $this->render_minicart_promos_html($cart);

        return $fragments;
    }

    /**
     * Build the classic mini-cart promo markup for the given cart.
     *
     * Shared by render_minicart_promos() (direct hook) and
     * add_minicart_promos_fragment() (AJAX refresh) so the HTML lives in one
     * place. Returns '' — the caller then prints nothing / clears the node —
     * whenever there is no cart or no applicable promo.
     *
     * "Applicable" mirrors StoreApiController::emit_promo_cart_notices() so the
     * classic mini-cart, the Block Cart notices and the Store API payload all
     * agree on what to surface: a free-ship-threshold promo nudges only while
     * still locked (progress present, not yet applied, something left to spend),
     * every other promo announces itself once it is actually applied, and a row
     * is skipped when it has no copy to show.
     *
     * @param \WC_Cart|null $cart
     * @return string Escaped HTML, or '' when there is nothing to show.
     */
    private function render_minicart_promos_html($cart)
    {
        if (!$cart) {
            return '';
        }

        $badges = \Drw\App\Models\PromoBadgeHelper::collect($cart);
        if (empty($badges)) {
            return '';
        }

        $rows = '';
        foreach ($badges as $badge) {
            $is_threshold = (null !== $badge['progress']);
            $remaining    = $is_threshold ? (float)$badge['progress']['remaining'] : 0.0;
            $applied      = !empty($badge['applied']);

            $should_show = $is_threshold
                ? (!$applied && $remaining > 0)
                : $applied;
            if (!$should_show) {
                continue;
            }

            // Prefer the merchant's cart_message; fall back to the rule title so
            // an applied promo with no copy still names itself rather than
            // rendering an empty pill.
            $text = ('' !== $badge['message']) ? $badge['message'] : $badge['title'];
            if ('' === $text) {
                continue;
            }

            $state_class = $applied ? 'is-applied' : 'is-progress';

            $rows .= '<div class="drw-minicart-promo ' . esc_attr($state_class) . '">'
                . '<span class="drw-minicart-promo__mark" aria-hidden="true"></span>'
                . '<span class="drw-minicart-promo__text">' . esc_html($text) . '</span>'
                . '</div>';
        }

        if ('' === $rows) {
            return '';
        }

        return '<div class="drw-minicart-promos">' . $rows . '</div>';
    }

    /**
     * Whether the classic mini-cart promos feature is enabled.
     *
     * Honours the features.show_minicart_promos setting (default true), so a
     * merchant can silence the mini-cart badges without affecting the Block Cart
     * / Store API surfaces. Defaults to enabled if SettingsModel is unavailable.
     *
     * @return bool
     */
    private function minicart_promos_enabled()
    {
        if (!class_exists('\\Drw\\App\\Models\\SettingsModel')) {
            return true;
        }

        return (bool) \Drw\App\Models\SettingsModel::get_setting('features.show_minicart_promos', true);
    }

    /**
     * Intercepts WooCommerce cart calculations to apply item-specific rules.
     *
     * @param \WC_Cart $cart WooCommerce Cart object
     */
    public function recalculate_cart_item_prices($cart)
    {
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }

        // Avoid infinite loop
        if ($this->is_recalculating) {
            return;
        }

        $this->is_recalculating = true;

        $engine = \Drw\App\Controllers\RulesEngine::instance();

        // SANDBOX MODE — resolved once per request. Read-only, additive: see
        // get_sandbox_rule() for the full safety contract. $sandbox_rule stays
        // null for every request except the admin's own, cookie-carrying one.
        $sandbox_rule = $this->get_sandbox_rule();

        // BOGO Auto-addition logic
        if (!$this->is_adding_to_cart) {
            $rules = $engine->get_active_rules();
            if (null !== $sandbox_rule) {
                // Local copy only (get_active_rules() returns cached_rules by
                // value) — this does NOT mutate RulesEngine's internal cache,
                // so it has zero effect on any other code path or user.
                $rules[] = $sandbox_rule;
            }
            if (!empty($rules)) {
                foreach ($rules as $rule) {
                    $adjustments = !empty($rule['adjustments']) ? (array)$rule['adjustments'] : [];
                    $type = !empty($adjustments['type']) ? $adjustments['type'] : '';

                    if ($type === 'bogo') {
                        if ($engine->is_rule_matched($rule, $cart)) {
                            $buy_qty = isset($adjustments['buy_qty']) ? (int)$adjustments['buy_qty'] : (isset($adjustments['buy_quantity']) ? (int)$adjustments['buy_quantity'] : 1);
                            $get_qty = isset($adjustments['get_qty']) ? (int)$adjustments['get_qty'] : (isset($adjustments['get_quantity']) ? (int)$adjustments['get_quantity'] : 1);
                            $get_product_type = isset($adjustments['get_product_type']) ? $adjustments['get_product_type'] : (isset($adjustments['apply_to']) ? $adjustments['apply_to'] : 'same');

                            $buy_products = isset($adjustments['buy_products']) ? (array)$adjustments['buy_products'] : [];
                            $buy_categories = isset($adjustments['buy_categories']) ? (array)$adjustments['buy_categories'] : [];
                            $get_products = isset($adjustments['get_products']) ? (array)$adjustments['get_products'] : [];
                            $get_categories = isset($adjustments['get_categories']) ? (array)$adjustments['get_categories'] : [];

                            if ($get_product_type === 'different') {
                                $total_buy_qty = 0;
                                foreach ($cart->get_cart() as $item) {
                                    if ($this->is_product_in_list($item['data'], $buy_products, $buy_categories)) {
                                        $total_buy_qty += (int)$item['quantity'];
                                    }
                                }

                                if ($total_buy_qty >= $buy_qty && !empty($get_products)) {
                                    $gift_in_cart = false;
                                    foreach ($cart->get_cart() as $item) {
                                        if ($this->is_product_in_list($item['data'], $get_products, $get_categories, false)) {
                                            $gift_in_cart = true;
                                            break;
                                        }
                                    }

                                    if (!$gift_in_cart) {
                                        $gift_product_id = (int)reset($get_products);
                                        if ($gift_product_id > 0) {
                                            $this->is_adding_to_cart = true;
                                            WC()->cart->add_to_cart($gift_product_id, $get_qty);
                                            $this->is_adding_to_cart = false;
                                            break;
                                        }
                                    }
                                }
                            } elseif ($get_product_type === 'same') {
                                foreach ($cart->get_cart() as $item) {
                                    $product = $item['data'];
                                    if ($this->is_product_in_list($product, $buy_products, $buy_categories)) {
                                        $qty = (int)$item['quantity'];
                                        if ($qty >= $buy_qty && $qty < ($buy_qty + $get_qty)) {
                                            $product_id = $product->get_id();
                                            $variation_id = $item['variation_id'];
                                            $variations = $item['variation'];

                                            $this->is_adding_to_cart = true;
                                            WC()->cart->add_to_cart($product_id, $get_qty, $variation_id, $variations);
                                            $this->is_adding_to_cart = false;
                                            break 2;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            
            // Get discounted price based on matching rules
            $discounted_price = $engine->calculate_cart_item_discount($cart_item, $cart);

            if ($discounted_price !== null) {
                // Apply the adjusted price to the product instance inside the cart
                $product->set_price($discounted_price);

                // Track metadata in the cart item session for display or saving later
                $cart->cart_contents[$cart_item_key]['drw_discounted'] = true;
                $cart->cart_contents[$cart_item_key]['drw_original_price'] = (float)$product->get_regular_price();
                $cart->cart_contents[$cart_item_key]['drw_discount_price'] = $discounted_price;

                // Attribute this discount to the originating promo (Vía B —
                // automatic promos compiled to wp_drw_rules), so
                // save_line_item_metadata() can stamp it onto the order line
                // item and resolve_order_promo_ids() can find it again at
                // payment/reservation time. See resolve_line_item_promo_id()
                // for the matching approach and its known limitations.
                $promo_id = $this->resolve_line_item_promo_id($product, $cart, $engine);
                if ($promo_id > 0) {
                    $cart->cart_contents[$cart_item_key]['drw_promo_id'] = $promo_id;
                }

                // Same attribution, for a manually-authored (non-promo) rule
                // instead: closes the gap where a rule created outside the
                // Promos wizard with its own usage_limit/limit_user was never
                // attributed to anything an order-completion hook could find,
                // so those caps were silently unenforced. See
                // resolve_line_item_rule_id() for the exact scope/limitations.
                $rule_id = $this->resolve_line_item_rule_id($product, $cart, $engine);
                if ($rule_id > 0) {
                    $cart->cart_contents[$cart_item_key]['drw_rule_id'] = $rule_id;
                }
            }
        }

        // SANDBOX MODE — additive per-item preview layer, applied ONLY when
        // get_sandbox_rule() resolved a valid, current-admin-owned override
        // above. Wrapped in try/catch so a bug here can only ever break the
        // admin's OWN preview cart, never fatal the request for anyone.
        if (null !== $sandbox_rule) {
            try {
                $this->apply_sandbox_item_adjustments($engine, $cart, $sandbox_rule);
            } catch (\Throwable $e) {
                error_log('[discount-rules-woo] Sandbox preview (item pricing) failed: ' . $e->getMessage());
            }
        }

        $this->is_recalculating = false;
    }

    /**
     * Best-effort identification of WHICH promo-compiled rule (Vía B —
     * automatic promos, source='promo') is responsible for discounting a
     * given cart item, so the discount can be attributed to a promo_id for
     * order-line-item metadata (see save_line_item_metadata() /
     * resolve_order_promo_ids()).
     *
     * Deliberately does NOT reach into RulesEngine's private compounding
     * pipeline (calculate_all_cart_discounts()/apply_rule_adjustments()) —
     * that logic is frozen. Instead this mirrors the EXACT same pattern
     * already used by the sandbox-preview layer above
     * (apply_sandbox_item_adjustments()): it re-applies RulesEngine's own
     * PUBLIC matching predicates (get_active_rules(), is_cart_level_rule(),
     * is_rule_matched(), is_product_targeted_by_rule()) plus an inlined copy
     * of the (private) should_skip_due_to_coupons() check, to find the
     * highest-priority promo-sourced, item-level rule that matches this cart
     * and targets this product.
     *
     * KNOWN LIMITATION: when more than one promo-sourced rule targets the
     * SAME item (stacking), or when the 'highest' compounding strategy picks
     * a different winning rule than the first one matched here, this may
     * attribute the discount to a promo that didn't end up contributing the
     * (whole) final price. This only affects which promo gets counted
     * against its usage_limit/limit_user and reservation bookkeeping for
     * that edge case — it never affects the actual price shown/charged,
     * which is still computed exclusively by RulesEngine. Flagged for the
     * orchestrator: a fully precise fix would require RulesEngine itself to
     * track per-item rule attribution, which is out of scope here (frozen
     * calculation logic).
     *
     * @param \WC_Product              $product
     * @param \WC_Cart                 $cart
     * @param \Drw\App\Controllers\RulesEngine $engine
     * @return int Promo id, or 0 if no promo-sourced rule could be matched.
     */
    private function resolve_line_item_promo_id($product, $cart, $engine)
    {
        $rules = $engine->get_active_rules();
        if (empty($rules)) {
            return 0;
        }

        // Mirrors RulesEngine::should_skip_due_to_coupons() (private) —
        // duplicated here rather than exposed, since it only reads a WP
        // option and public cart data.
        $global_no_stack = (bool)get_option('drw_global_no_coupon_stacking', false);
        $cart_has_coupons = (bool)$cart->get_applied_coupons();

        foreach ($rules as $rule) {
            $promo_id = !empty($rule['promo_id']) ? (int)$rule['promo_id'] : 0;
            if ($promo_id <= 0 || (isset($rule['source']) && $rule['source'] !== 'promo')) {
                continue;
            }

            if ($engine->is_cart_level_rule($rule)) {
                // Cart-level fees/free-shipping aren't tied to a single line
                // item; skip (not attributable via order line-item meta).
                continue;
            }

            $rule_no_stack = !empty($rule['no_coupon_stacking']);
            if (($global_no_stack || $rule_no_stack) && $cart_has_coupons) {
                continue;
            }

            if (!$engine->is_rule_matched($rule, $cart)) {
                continue;
            }

            if (!$engine->is_product_targeted_by_rule($rule, $product)) {
                continue;
            }

            return $promo_id;
        }

        return 0;
    }

    /**
     * Best-effort identification of WHICH manually-authored (source IS NULL,
     * no promo_id) rule with a usage_limit/limit_user configured is
     * responsible for discounting a given cart item, so its usage can be
     * attributed and reserved/tracked exactly like a Vía B promo-compiled
     * rule (see resolve_order_rule_ids() / reserve_promo_usage()).
     *
     * Closes a real gap: before this, RuleModel::get_active_rules() would
     * stop OFFERING an exhausted rule to future shoppers (its usage_limit
     * check), but nothing ever incremented used_count or enforced limit_user
     * for a rule created outside the Promos wizard, because the ONLY
     * order-lifecycle hooks that touch usage bookkeeping
     * (track_promo_redemptions()/reserve_promo_usage()) resolved promo ids
     * exclusively — a manually-authored rule has no promo_id to resolve at
     * all.
     *
     * Deliberately mirrors resolve_line_item_promo_id() call-for-call (same
     * frozen-RulesEngine constraint, same public-matching-predicate
     * approach), with two differences: it explicitly SKIPS any rule that
     * already has a promo_id (those are promo-sourced and already handled by
     * resolve_line_item_promo_id()/_drw_promo_id — stamping both meta keys
     * for the same rule would double-reserve it), and it skips a rule with
     * neither usage_limit nor limit_user configured, since there is nothing
     * to reserve or track for it.
     *
     * Shares resolve_line_item_promo_id()'s documented KNOWN LIMITATION:
     * cart-level rules (percentage/fixed cart-wide fees, free_shipping) are
     * not attributable via order line-item meta and are skipped here too —
     * a manually-authored CART-LEVEL rule's usage_limit/limit_user remains
     * unenforced by the reservation system, same as for a promo-compiled one.
     *
     * @param \WC_Product              $product
     * @param \WC_Cart                 $cart
     * @param \Drw\App\Controllers\RulesEngine $engine
     * @return int Rule id, or 0 if no attributable rule could be matched.
     */
    private function resolve_line_item_rule_id($product, $cart, $engine)
    {
        $rules = $engine->get_active_rules();
        if (empty($rules)) {
            return 0;
        }

        $global_no_stack = (bool)get_option('drw_global_no_coupon_stacking', false);
        $cart_has_coupons = (bool)$cart->get_applied_coupons();

        foreach ($rules as $rule) {
            $promo_id = !empty($rule['promo_id']) ? (int)$rule['promo_id'] : 0;
            if ($promo_id > 0) {
                // Promo-sourced -- already handled via _drw_promo_id above.
                continue;
            }

            if (empty($rule['usage_limit']) && empty($rule['limit_user'])) {
                // No cap configured on this rule: nothing to reserve/track.
                continue;
            }

            if ($engine->is_cart_level_rule($rule)) {
                continue;
            }

            $rule_no_stack = !empty($rule['no_coupon_stacking']);
            if (($global_no_stack || $rule_no_stack) && $cart_has_coupons) {
                continue;
            }

            if (!$engine->is_rule_matched($rule, $cart)) {
                continue;
            }

            if (!$engine->is_product_targeted_by_rule($rule, $product)) {
                continue;
            }

            return !empty($rule['id']) ? (int)$rule['id'] : 0;
        }

        return 0;
    }

    /**
     * SANDBOX MODE — resolve (and cache for the rest of the request) whether
     * the CURRENT user has a valid sandbox override active, per
     * PromoBridgeController::get_sandboxed_rule_for_current_user().
     *
     * This method, and everything it feeds into below, is the ONLY place
     * CartController deviates from the normal engine-driven calculation. It
     * is a strict no-op — returns null, changing nothing — for every request
     * that isn't the sandboxing admin's own: no cookie, wrong/expired/forged
     * cookie, cookie for a different user, or the current user lacking
     * manage_woocommerce all fall through to null here (see the bridge
     * method for the full check list). Wrapped in try/catch so any
     * unexpected failure (e.g. a DB hiccup resolving the promo/rule) degrades
     * to "sandbox off" instead of breaking cart calculation.
     *
     * @return array|null
     */
    private function get_sandbox_rule()
    {
        if ($this->sandbox_rule_resolved) {
            return $this->sandbox_rule;
        }
        $this->sandbox_rule_resolved = true;

        try {
            if (class_exists('\\Drw\\App\\Controllers\\PromoBridgeController')) {
                $this->sandbox_rule = \Drw\App\Controllers\PromoBridgeController::get_sandboxed_rule_for_current_user();
            }
        } catch (\Throwable $e) {
            error_log('[discount-rules-woo] Sandbox cookie resolution failed: ' . $e->getMessage());
            $this->sandbox_rule = null;
        }

        return $this->sandbox_rule;
    }

    /**
     * SANDBOX MODE — apply the ONE sandboxed rule's adjustment on top of
     * whatever price the real engine already computed for this cart, for the
     * current (admin, cookie-validated) request only.
     *
     * Deliberately does NOT touch RulesEngine's private $cached_rules / the
     * compounding pipeline in calculate_all_cart_discounts() — that cache has
     * no public mutator, and reaching into it was judged too risky to do
     * blindly for every other shopper's cart on the site. Instead this reuses
     * RulesEngine's existing PUBLIC matching helpers (is_rule_matched(),
     * is_product_targeted_by_rule()) plus the same standalone Adjustment
     * classes (Bogo / BundleSet) RulesEngine itself delegates to, and layers
     * the result on top of the current product price. Cart-level
     * percentage/fixed fees and free_shipping are handled separately in
     * apply_cart_wide_fees() / modify_shipping_package_rates() below.
     *
     * @param \Drw\App\Controllers\RulesEngine $engine
     * @param \WC_Cart $cart
     * @param array $rule Sandboxed rule (RuleModel-formatted row).
     */
    private function apply_sandbox_item_adjustments($engine, $cart, array $rule)
    {
        if ($engine->is_cart_level_rule($rule)) {
            // percentage/fixed cart-level and free_shipping are applied by
            // apply_cart_wide_fees() / modify_shipping_package_rates().
            return;
        }

        if (!$engine->is_rule_matched($rule, $cart)) {
            return;
        }

        $adjustments = !empty($rule['adjustments']) ? (array)$rule['adjustments'] : [];
        $type = !empty($adjustments['type']) ? $adjustments['type'] : '';

        if ($type === 'percentage' || $type === 'fixed') {
            $value = (float)(isset($adjustments['value']) ? $adjustments['value'] : 0);
            foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
                $product = $cart_item['data'];
                if (!$engine->is_product_targeted_by_rule($rule, $product)) {
                    continue;
                }
                $current_price = (float)$product->get_price();
                $new_price = ($type === 'percentage')
                    ? $current_price - ($current_price * ($value / 100))
                    : $current_price - $value;
                $this->set_sandbox_item_price($cart, $cart_item_key, $product, $new_price);
            }
        } elseif ($type === 'bulk') {
            $tiers = !empty($adjustments['tiers']) ? (array)$adjustments['tiers'] : [];
            foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
                $product = $cart_item['data'];
                if (!$engine->is_product_targeted_by_rule($rule, $product)) {
                    continue;
                }
                $qty = (int)$cart_item['quantity'];
                foreach ($tiers as $tier) {
                    $min_qty = isset($tier['min']) ? (int)$tier['min'] : 0;
                    $max_qty = isset($tier['max']) && $tier['max'] !== '' ? (int)$tier['max'] : PHP_INT_MAX;
                    if ($qty < $min_qty || $qty > $max_qty) {
                        continue;
                    }
                    $tier_type  = !empty($tier['type']) ? $tier['type'] : 'percentage';
                    $tier_value = (float)(!empty($tier['value']) ? $tier['value'] : 0);
                    $current_price = (float)$product->get_price();
                    $new_price = ($tier_type === 'fixed')
                        ? $current_price - $tier_value
                        : $current_price - ($current_price * ($tier_value / 100));
                    $this->set_sandbox_item_price($cart, $cart_item_key, $product, $new_price);
                    break;
                }
            }
        } elseif ($type === 'bogo') {
            $bogo_engine = new \Drw\App\Adjustments\Bogo();
            $bogo_results = $bogo_engine->calculate($adjustments, $cart);
            $this->apply_sandbox_ratio_results($cart, $bogo_results);
        } elseif ($type === 'bundle_set') {
            $bundle_engine = new \Drw\App\Adjustments\BundleSet();
            $bundle_results = $bundle_engine->calculate($adjustments, $cart);
            if (!empty($bundle_results['applied']) && !empty($bundle_results['items'])) {
                $this->apply_sandbox_ratio_results($cart, $bundle_results['items']);
            }
        }
    }

    /**
     * SANDBOX MODE helper — Bogo::calculate()/BundleSet::calculate() both
     * return { cart_item_key => new_unit_price } computed off each item's
     * REGULAR price. Convert that into a ratio and apply it to the item's
     * CURRENT price (which may already reflect a real, non-sandboxed
     * discount), exactly mirroring how RulesEngine's own private
     * apply_rule_adjustments() composes bogo/bundle_set with earlier rules.
     *
     * @param \WC_Cart $cart
     * @param array $results { cart_item_key => new_unit_price }
     */
    private function apply_sandbox_ratio_results($cart, array $results)
    {
        if (empty($results)) {
            return;
        }
        $cart_items = $cart->get_cart();
        foreach ($results as $cart_item_key => $new_unit_price) {
            if (!isset($cart_items[$cart_item_key])) {
                continue;
            }
            $product = $cart_items[$cart_item_key]['data'];
            $reg_price = (float)$product->get_regular_price();
            if ($reg_price <= 0) {
                continue;
            }
            $ratio = $new_unit_price / $reg_price;
            $new_price = (float)$product->get_price() * $ratio;
            $this->set_sandbox_item_price($cart, $cart_item_key, $product, $new_price);
        }
    }

    /**
     * SANDBOX MODE helper — apply a computed price to the product instance
     * and mirror recalculate_cart_item_prices()'s own metadata bookkeeping so
     * the existing UI (format_cart_item_price(), savings totals, etc.) shows
     * the sandboxed discount exactly like a real one, for this cart only.
     *
     * @param \WC_Cart $cart
     * @param string $cart_item_key
     * @param \WC_Product $product
     * @param float $new_price
     */
    private function set_sandbox_item_price($cart, $cart_item_key, $product, $new_price)
    {
        $new_price = max(0.0, (float)$new_price);
        $product->set_price($new_price);
        $cart->cart_contents[$cart_item_key]['drw_discounted'] = true;
        if (!isset($cart->cart_contents[$cart_item_key]['drw_original_price'])) {
            $cart->cart_contents[$cart_item_key]['drw_original_price'] = (float)$product->get_regular_price();
        }
        $cart->cart_contents[$cart_item_key]['drw_discount_price'] = $new_price;
    }

    /**
     * Calculates cart subtotal rules and applies them as a negative fee.
     *
     * @param \WC_Cart $cart WooCommerce Cart object
     */
    public function apply_cart_wide_fees($cart)
    {
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }

        $engine = \Drw\App\Controllers\RulesEngine::instance();
        $discounts = $engine->calculate_cart_level_discounts($cart);

        if (!empty($discounts['fees'])) {
            // Each cart-level rule already caps its OWN fee at the subtotal in
            // RulesEngine::apply_rule_adjustments(), but several non-exclusive
            // automatic cart promos can still STACK and, combined, exceed the
            // real cart subtotal — an unintended free/negative checkout. Scale
            // the whole set down proportionally so the fees can never sum past
            // the subtotal actually being charged for the items at this point.
            $fees = $this->scale_fees_to_subtotal($discounts['fees'], (float)$cart->get_subtotal());
            foreach ($fees as $fee) {
                $cart->add_fee($fee['name'], $fee['amount'], true);
            }
        }

        // SANDBOX MODE — additive cart-level fee for a sandboxed
        // percentage/fixed promo (mirrors RulesEngine's own cart-level fee
        // branch). No-op unless get_sandbox_rule() resolved a valid,
        // current-admin-owned override; see that method for the full
        // safety contract. Wrapped in try/catch so a bug here can only ever
        // break the admin's OWN preview cart, never fatal the request.
        try {
            $sandbox_rule = $this->get_sandbox_rule();
            if (null !== $sandbox_rule && $engine->is_cart_level_rule($sandbox_rule) && $engine->is_rule_matched($sandbox_rule, $cart)) {
                $adjustments = !empty($sandbox_rule['adjustments']) ? (array)$sandbox_rule['adjustments'] : [];
                $type = !empty($adjustments['type']) ? $adjustments['type'] : '';
                if ($type === 'percentage' || $type === 'fixed') {
                    $subtotal = (float)$cart->get_subtotal();
                    $value = (float)(isset($adjustments['value']) ? $adjustments['value'] : 0);
                    $fee_amount = ($type === 'percentage') ? $subtotal * ($value / 100) : $value;
                    if ($fee_amount > 0) {
                        $fee_amount = min($fee_amount, $subtotal);
                        $label = !empty($sandbox_rule['title']) ? $sandbox_rule['title'] : __('Sandbox promo', 'discount-rules-woo');
                        $cart->add_fee('[Sandbox] ' . $label, -$fee_amount, true);
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[discount-rules-woo] Sandbox preview (cart fee) failed: ' . $e->getMessage());
        }
    }

    /**
     * Scale stacked cart-wide discount fees down so their combined magnitude
     * can never exceed the cart subtotal they are applied against.
     *
     * RulesEngine::apply_rule_adjustments() already caps each rule's OWN fee at
     * the subtotal, but several non-exclusive automatic cart promos can still
     * stack: the sum of their (negative) amounts can drop the order below zero,
     * i.e. an unintended free/negative checkout. This is the last line of
     * defence — when the combined discount would exceed $subtotal, every fee is
     * multiplied by (subtotal / total) so the fees sum to exactly the subtotal
     * while each fee keeps its relative share; otherwise the fees are returned
     * untouched.
     *
     * Pure and side-effect free so it can be unit-tested in isolation. Fee
     * amounts are expected to be negative (discounts), matching the shape
     * produced by RulesEngine::apply_rule_adjustments():
     * [ 'name' => string, 'amount' => float ].
     *
     * @param array $fees     Fees, each [ 'name' => string, 'amount' => float ].
     * @param float $subtotal Real cart subtotal to cap the combined fees at.
     * @return array Fees with amounts scaled proportionally only when the cap
     *               is exceeded; otherwise the input array unchanged.
     */
    private function scale_fees_to_subtotal(array $fees, $subtotal)
    {
        $subtotal = (float)$subtotal;

        // An empty set has nothing to scale.
        if (empty($fees)) {
            return $fees;
        }

        $total = 0.0;
        foreach ($fees as $fee) {
            $total += abs(isset($fee['amount']) ? (float)$fee['amount'] : 0.0);
        }

        // Combined discount already fits within the subtotal (or there is
        // nothing to scale): leave every fee exactly as-is. No re-casting on
        // this path, so the input array is returned bit-for-bit unchanged.
        if ($total <= 0.0 || $total <= $subtotal) {
            return $fees;
        }

        // A real cart subtotal is never negative; clamp defensively so the
        // ratio can only shrink magnitudes, never flip a discount into a
        // surcharge. When the real subtotal is 0 (e.g. a fully item-discounted
        // cart) every fee scales to 0, which still sums to exactly the
        // subtotal and keeps checkout from ever going negative.
        $ratio = max(0.0, $subtotal) / $total;
        foreach ($fees as $key => $fee) {
            $amount = isset($fee['amount']) ? (float)$fee['amount'] : 0.0;
            $fees[$key]['amount'] = $amount * $ratio;
        }

        return $fees;
    }

    /**
     * Attaches metadata of the applied discounts to the order items.
     *
     * @param \WC_Order_Item_Product $item Order line item object
     * @param string $cart_item_key Cart item unique key
     * @param array $values Cart item array values
     * @param \WC_Order $order WooCommerce Order object
     */
    public function save_line_item_metadata($item, $cart_item_key, $values, $order)
    {
        if (!empty($values['drw_discounted'])) {
            $original = $values['drw_original_price'];
            $discounted = $values['drw_discount_price'];
            $saving = $original - $discounted;

            if ($saving > 0) {
                // Store saved amount in item meta (visible in admin backend)
                $item->add_meta_data('_drw_original_price', $original, true);
                $item->add_meta_data('_drw_discount_price', $discounted, true);
                $item->add_meta_data(
                    __('Ahorro OmniDiscount', 'discount-rules-woo'),
                    wc_price($saving * (int)$values['quantity']),
                    false
                );
            }

            // Stamp the originating Vía B (automatic) promo id, resolved by
            // resolve_line_item_promo_id() at cart-recalculation time, so
            // resolve_order_promo_ids() can find it again once the order is
            // paid/reserved. Absent (0/unset) for manually-authored rules
            // (no promo_id at all) — nothing to attribute in that case.
            if (!empty($values['drw_promo_id'])) {
                $item->add_meta_data('_drw_promo_id', (int)$values['drw_promo_id'], true);
            }

            // Same stamping for a manually-authored (non-promo) rule with its
            // own usage_limit/limit_user, resolved by
            // resolve_line_item_rule_id() at cart-recalculation time — see
            // resolve_order_rule_ids() for how this is read back at
            // order-completion/reservation time.
            if (!empty($values['drw_rule_id'])) {
                $item->add_meta_data('_drw_rule_id', (int)$values['drw_rule_id'], true);
            }
        }
    }

    /**
     * Count real promo redemptions for a paid order, exactly once per order.
     *
     * Resolves which promos participated in the order (see
     * resolve_order_promo_ids()) and, for each one:
     *   - bumps the promo's usage counter (PromoModel::increment_usage()), and
     *   - confirms any 'reserved' redemption row reserve_promo_usage() created
     *     for it into 'confirmed' (RuleModel::confirm_usage()) — a no-op for
     *     Vía A (coupon) promos, which never had a rule_id to reserve against.
     *
     * HPOS-safe: order/line-item data is read ONLY through $order->get_coupon_codes(),
     * $order->get_items() and ...->get_meta(); nothing touches postmeta/posts
     * directly, and the counted flag is persisted with update_meta_data()/save().
     * Idempotency: $order->get_meta('_drw_promos_counted') short-circuits repeat
     * runs (processing -> completed, order re-saves, etc.) so no promo is ever
     * double-counted, and no redemption double-confirmed, for the same order.
     *
     * Concurrency (round-7 audit finding): the '_drw_promos_counted' check
     * above is a plain in-PHP read, and the flag is only persisted at the very
     * end via update_meta_data()/save(). Two workers racing the SAME order_id
     * (a realistic case: gateway webhook redelivery, or 'processing' and
     * 'completed' firing close together for the same order) could otherwise
     * both read the flag as unset before either saves, and both run the
     * increment_usage()/confirm_usage() loop below — double-counting
     * wp_drw_promos.uses (the Analytics dashboard's ground-truth counter).
     * This is closed with a real cross-process/cross-connection mutex: a
     * MySQL GET_LOCK() named lock keyed to the order id, held for the
     * duration of this method. A second worker either waits for the first to
     * finish (typical case: the whole method runs in milliseconds) and then
     * correctly sees the just-persisted '_drw_promos_counted' flag, or —
     * genuine contention timeout — bails out entirely rather than proceed
     * unprotected (safe: the lock holder is already doing this exact work for
     * this order, so skipping here cannot lose a real count).
     *
     * Degrades gracefully rather than hard-depending on GET_LOCK() support:
     * a driver/environment where it is unavailable reports NULL (a real
     * timeout reports the literal 0), in which case this falls back to the
     * pre-existing (non-atomic) guard rather than refuse to count promos at
     * all. On this plugin's actual InnoDB/MySQL deployment target, GET_LOCK()
     * is available and closes the race for real.
     *
     * Reconnect guard (round-10 audit finding): GET_LOCK()'s named lock is
     * scoped to the MySQL SESSION that acquired it -- if $wpdb transparently
     * reconnects mid-method (its own "MySQL server has gone away" recovery
     * in wp-includes/class-wpdb.php check_connection(), swapping $wpdb->dbh
     * for a brand-new connection object), MySQL auto-releases the lock the
     * instant the old session drops. A second worker blocked on GET_LOCK()
     * for the same order could then acquire it and start running
     * concurrently while this call keeps executing (unprotected, on the new
     * handle) as if it still held exclusivity -- reopening exactly the
     * double-count race this lock exists to close. wpdb_connection_fingerprint()/
     * wpdb_connection_still_open() below (same technique as RuleModel's
     * connection_fingerprint()/connection_still_open()) detect that swap: if
     * the connection changed after a real lock acquisition, this bails out
     * rather than trust a mutex that may no longer be held. A NULL
     * fingerprint (degraded/no-lock fallback, or a test stub with no ->dbh)
     * never triggers the guard -- there is no real mutex to lose in that case.
     *
     * @param int            $order_id Order ID passed by the status hook.
     * @param \WC_Order|null $order    Order object passed by the status hook.
     */
    public function track_promo_redemptions($order_id, $order = null)
    {
        if (!($order instanceof \WC_Order)) {
            $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        }
        if (!$order) {
            return;
        }

        global $wpdb;
        $lock_name = 'drw_track_promo_' . (int)$order_id;
        $lock_result = (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var') && method_exists($wpdb, 'prepare'))
            ? $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 10))
            : null;

        // A literal 0 (as opposed to NULL) means GET_LOCK() genuinely timed
        // out waiting on another connection that already holds this order's
        // lock -- real contention, not a degraded/unsupported environment.
        // Bail out: that other worker owns this order's counting pass.
        if ($lock_result === 0 || $lock_result === '0') {
            return;
        }

        // A real lock was actually acquired (as opposed to the degraded
        // fallback where GET_LOCK() itself is unavailable/unsupported and
        // $lock_result is NULL) only when MySQL reports the literal 1.
        // Fingerprint the connection that holds it so a later reconnect can
        // be detected -- see the reconnect-guard docblock above.
        $lock_acquired = ($lock_result === 1 || $lock_result === '1');
        $lock_conn_id  = $lock_acquired ? self::wpdb_connection_fingerprint($wpdb) : null;

        try {
            // Re-resolve fresh order state now that the lock is held (or, in
            // the degraded/no-lock fallback, immediately): the $order object
            // passed in by the hook may predate a concurrent worker that
            // already persisted '_drw_promos_counted' while we were waiting
            // on the lock -- trusting the caller's in-memory copy here would
            // defeat the whole point of serializing on it.
            $fresh_order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
            if ($fresh_order instanceof \WC_Order) {
                $order = $fresh_order;
            }

            // Already tallied for this order: never count twice.
            if ($order->get_meta('_drw_promos_counted')) {
                return;
            }

            // Reconnect guard: if a real lock was acquired but $wpdb is no
            // longer running on the same connection that acquired it, the
            // named lock may have been silently dropped (MySQL releases a
            // session's GET_LOCK()s when that session's connection ends) and
            // a concurrent worker could already be counting this same order
            // unprotected. Bail out rather than proceed as if still
            // serialized -- safe: the other worker (real holder or not) is
            // already doing this exact work for this order.
            if ($lock_acquired && !self::wpdb_connection_still_open($wpdb, $lock_conn_id)) {
                return;
            }

            $promo_ids = $this->resolve_order_promo_ids($order);

            // One increment (+ one confirm, when the promo compiled to a rule) per
            // promo per order (resolve_order_promo_ids() already de-duplicates).
            foreach ($promo_ids as $pid) {
                \Drw\App\Models\PromoModel::increment_usage($pid);

                $promo = \Drw\App\Models\PromoModel::get_promo($pid);
                $rule_id = ($promo && !empty($promo['rule_id'])) ? (int)$promo['rule_id'] : 0;
                if ($rule_id > 0) {
                    \Drw\App\Models\RuleModel::confirm_usage($rule_id, (int)$order_id);
                }
            }

            // Same confirmation for a manually-authored (non-promo) rule's own
            // reservation — see resolve_order_rule_ids() / reserve_promo_usage().
            // No PromoModel::increment_usage() call here: there is no promo row
            // to bump for a directly-authored rule, only its own used_count
            // (already incremented by RuleModel::try_reserve_usage() at
            // reservation time — confirm_usage() only flips the redemption row's
            // status, it never re-increments).
            foreach ($this->resolve_order_rule_ids($order) as $rid) {
                \Drw\App\Models\RuleModel::confirm_usage($rid, (int)$order_id);
            }

            // IDENTITY DOCUMENT / FIRST-PURCHASE VERIFICATION -- mark this
            // order as having redeemed a "welcome" benefit (popup-minted OR
            // a merchant-authored promo of type='welcome', see
            // order_has_welcome_coupon()) the SAME moment real usage is
            // counted (order reaches processing/completed), never earlier.
            // Single write point, reused as the sole source of truth by
            // enforce_first_purchase_welcome_coupon() below via
            // prior_welcome_coupon_redemption_exists(). Piggybacks on this
            // method's own '_drw_promos_counted' idempotency guard/lock
            // above and the single save() call below -- no extra DB write.
            if ($this->order_has_welcome_coupon($order)) {
                $order->update_meta_data('_drw_welcome_coupon_used', 1);
            }

            // Flag the order as counted even when no promo matched, so a promo-less
            // order isn't re-scanned on every subsequent status transition.
            $order->update_meta_data('_drw_promos_counted', 1);
            $order->save();
        } finally {
            if ($lock_result !== null && isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var')) {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
            }
        }
    }

    /**
     * Identity fingerprint of $wpdb's CURRENT live connection handle, used by
     * track_promo_redemptions()'s GET_LOCK() mutex to detect wpdb's own
     * transparent "MySQL server has gone away" reconnect happening between
     * lock acquisition and release. Same technique (and same rationale) as
     * RuleModel::connection_fingerprint(), duplicated locally in this class
     * rather than reused across classes so this purely-defensive, low-severity
     * guard adds no new cross-class coupling to RuleModel's transaction
     * machinery.
     *
     * Returns null (never matches a real fingerprint, so
     * wpdb_connection_still_open() only reports true against another null)
     * when $wpdb->dbh isn't a live object -- e.g. a test stub with no such
     * property, or a legacy non-mysqli driver -- so this defence degrades to
     * a safe no-op rather than a false positive on setups it can't inspect.
     *
     * @param object $wpdb
     * @return string|int|null
     */
    private static function wpdb_connection_fingerprint($wpdb)
    {
        if (!isset($wpdb->dbh) || !is_object($wpdb->dbh)) {
            return null;
        }

        if ($wpdb->dbh instanceof \mysqli) {
            return 'mysqli:' . $wpdb->dbh->thread_id;
        }

        return spl_object_id($wpdb->dbh);
    }

    /**
     * Whether $wpdb is still running on the SAME connection handle whose
     * fingerprint was captured right after a successful GET_LOCK(). See
     * wpdb_connection_fingerprint() for the reconnect scenario this guards
     * against.
     *
     * @param object   $wpdb
     * @param int|null $expected_conn_id Fingerprint captured at lock acquisition.
     * @return bool
     */
    private static function wpdb_connection_still_open($wpdb, $expected_conn_id)
    {
        return self::wpdb_connection_fingerprint($wpdb) === $expected_conn_id;
    }

    /**
     * Resolve the unique set of promo ids that participated in an order.
     *
     * Shared identification logic used by BOTH track_promo_redemptions() (real
     * usage counting on payment) and reserve_promo_usage() (reservation at order
     * creation), so the two can never drift on what "this promo applied to this
     * order" means:
     *   - Vía A (code-based promos): each applied coupon code is matched back to
     *     its promo. Promo codes are stored upper-cased, WooCommerce returns
     *     applied codes lower-cased, so the code is normalised before lookup.
     *   - Vía B (automatic promos compiled to drw_rules): the discounted line
     *     item carries the originating promo id as item meta (_drw_promo_id /
     *     promo_id), read through the CRUD data-store API.
     *
     * HPOS-safe: reads ONLY through $order->get_coupon_codes(), $order->get_items()
     * and ...->get_meta(); nothing touches postmeta/posts directly.
     *
     * @param \WC_Order $order
     * @return int[] Unique promo ids, in first-seen order.
     */
    private function resolve_order_promo_ids($order)
    {
        $promo_ids = [];

        // Vía A – coupon-backed promos.
        foreach ($order->get_coupon_codes() as $code) {
            $promo = \Drw\App\Models\PromoModel::get_promo_by_code(strtoupper((string)$code));
            if ($promo && !empty($promo['id'])) {
                $promo_ids[(int)$promo['id']] = true;
            }
        }

        // Vía B – automatic promos stamped onto their discounted line items.
        foreach ($order->get_items() as $item) {
            $pid = $item->get_meta('_drw_promo_id', true);
            if ($pid === '' || $pid === null) {
                $pid = $item->get_meta('promo_id', true);
            }
            if ($pid !== '' && $pid !== null && (int)$pid > 0) {
                $promo_ids[(int)$pid] = true;
            }
        }

        return array_keys($promo_ids);
    }

    /**
     * Resolve the unique set of MANUALLY-AUTHORED (source IS NULL, no
     * promo_id) rule ids that participated in an order, via the '_drw_rule_id'
     * item meta stamped by resolve_line_item_rule_id() at cart-recalculation
     * time / save_line_item_metadata() at order-creation time.
     *
     * Deliberately SEPARATE from resolve_order_promo_ids(): that method only
     * ever resolves promo-compiled (Vía B, source='promo') rules through
     * their promo_id wrapper, and resolve_line_item_rule_id() explicitly
     * skips any rule that already has a promo_id — so the two id sets never
     * overlap. Callers combine both when driving the shared reservation/
     * confirmation primitives (RuleModel::try_reserve_usage()/confirm_usage(),
     * both of which already accept a nullable $promo_id for exactly this
     * case).
     *
     * HPOS-safe: reads ONLY through $order->get_items() and ...->get_meta();
     * nothing touches postmeta/posts directly.
     *
     * @param \WC_Order $order
     * @return int[] Unique rule ids, in first-seen order.
     */
    private function resolve_order_rule_ids($order)
    {
        $rule_ids = [];

        foreach ($order->get_items() as $item) {
            $rid = $item->get_meta('_drw_rule_id', true);
            if ($rid !== '' && $rid !== null && (int)$rid > 0) {
                $rule_ids[(int)$rid] = true;
            }
        }

        return array_keys($rule_ids);
    }

    /**
     * Store API adapter for reserve_promo_usage(): the
     * 'woocommerce_store_api_checkout_order_processed' action (see
     * register_hooks()) passes ONLY the order object, not ($order_id,
     * $posted_data, $order) like the classic hook does. Re-shapes the call so
     * both flows share the single reserve_promo_usage() implementation below.
     *
     * A thrown \Exception (or RouteException — see reserve_promo_usage())
     * propagates straight back up through this action into
     * Automatic\WooCommerce\StoreApi\Routes\V1\AbstractRoute::get_response(),
     * which wraps get_response_by_request_method() in try/catch and turns any
     * \Exception into a blocking error response (RouteException specifically
     * -> a clean 400 with our error code/message via
     * get_route_error_response(); a plain \Exception -> a 500
     * 'woocommerce_rest_unknown_server_error' response that still carries our
     * message as getMessage() — verified directly in
     * src/StoreApi/Routes/V1/AbstractRoute.php on this site's installed
     * WooCommerce). Either way checkout is genuinely aborted client-side; it is
     * NOT silently swallowed.
     *
     * @param \WC_Order $order Order object passed by the Store API hook.
     * @throws \Exception When a limit_user hard cap denies the reservation.
     */
    public function reserve_promo_usage_store_api($order)
    {
        if (!($order instanceof \WC_Order)) {
            return;
        }

        $this->reserve_promo_usage((int)$order->get_id(), [], $order);
    }

    /**
     * Reserve per-customer/global usage for every rule-backed promo that
     * applied to a newly-created order. Hooked to BOTH
     * 'woocommerce_checkout_order_processed' (classic checkout, this exact
     * 3-arg signature) and, via reserve_promo_usage_store_api() above,
     * 'woocommerce_store_api_checkout_order_processed' (Store API / Cart &
     * Checkout Blocks — this site's actual checkout, verified live). See
     * register_hooks() for the full verification note on why BOTH hooks are
     * required and the classic-only woocommerce_after_checkout_validation
     * cannot be used.
     *
     * Reserves usage for BOTH: Vía B (automatic promos compiled to a
     * wp_drw_rules row, resolved via PromoModel::get_promo()['rule_id']) AND
     * manually-authored rules (source IS NULL, created outside the Promos
     * wizard) that carry their own usage_limit/limit_user, resolved via
     * resolve_order_rule_ids() — closing the gap where the latter's caps were
     * never enforced by anything (see that method's docblock and
     * resolve_line_item_rule_id()). Vía A (coupon-based) promos have no
     * compiled rule row: their per-customer cap is already enforced natively
     * by WooCommerce's own WC_Coupon::usage_limit_per_user (see
     * PromoBridgeController::build_coupon_data()), so there is nothing for this
     * reservation system to add for them.
     *
     * Both id sets share the exact same per-rule reservation/denial policy —
     * see attempt_rule_reservation() below, extracted specifically so the two
     * loops can never drift apart on this. Hard-block vs soft-fail,
     * deliberately asymmetric:
     *   - A rule with limit_user set is a NEW hard per-customer cap being added
     *     by this feature. If try_reserve_usage() denies it, checkout is
     *     genuinely aborted: an \Automattic\WooCommerce\StoreApi\Exceptions\RouteException
     *     is thrown when that class is loaded (guarded by class_exists(), so
     *     this never hard-depends on Store API/Blocks being active) for a
     *     clean, properly-coded Store API error response; otherwise a plain
     *     \Exception is thrown, which both the classic
     *     WC_Checkout::process_checkout() catch block (wc_add_notice()) and the
     *     Store API's own generic \Exception catch (AbstractRoute::get_response())
     *     turn into a blocking checkout error carrying this exact message —
     *     verified directly against this site's installed WooCommerce core
     *     source (includes/class-wc-checkout.php and
     *     src/StoreApi/Routes/V1/AbstractRoute.php), not assumed.
     *   - A rule with ONLY a bare usage_limit (no limit_user) failing
     *     reservation is NOT newly promoted to a hard block here: usage_limit
     *     already existed before this feature and was never enforced as a hard
     *     checkout-blocking cap (RuleModel::get_active_rules() only ever used it
     *     to stop offering an exhausted rule to FUTURE shoppers). Turning a
     *     race on the global counter into a first-time hard block would be a
     *     bigger behavior change than this feature asked for, so it is logged
     *     only. Flagged explicitly for the orchestrator to override if this
     *     reasoning is wrong.
     *     ROUND-8 AUDIT FINDING, evaluated and deliberately left unchanged:
     *     an adversarial audit flagged this soft-fail as letting orders
     *     complete with the discount applied past a limited promo's cap.
     *     Confirmed accurate, but NOT changed here — it is a considered
     *     tradeoff (see above) already pinned down by an explicit regression
     *     assertion (tests/test-cartcontroller-promo-reservation.php case
     *     (d)), and promoting it to a hard block is a real checkout-behavior
     *     change that needs the explicit product sign-off this docblock
     *     already asks for, not a silent flip inside an adversarial-finding
     *     fix pass on checkout-path-critical code.
     *
     * COMPENSATING ROLLBACK (round-8 audit finding: cross-rule reservation
     * griefing): each attempt_rule_reservation() call commits its own
     * successful reservation in an independent transaction (see
     * RuleModel::try_reserve_usage()) BEFORE the next rule in either loop
     * below is evaluated. Without the try/catch here, an attacker could pair
     * a valuable target promo with a cheaply-exhausted limit_user rule (using
     * an unverified guest email) to guarantee the target rule's slot is
     * committed and then force a hard-block Exception on the second rule —
     * for free, repeatably draining a limited promo's inventory with no
     * order ever completing. Every rule_id successfully reserved by THIS
     * call (attempt_rule_reservation() returned true) is tracked in
     * $reserved_this_call; if a later rule in either loop then throws, every
     * tracked reservation is released via RuleModel::release_usage() before
     * the exception is allowed to propagate, so a hard-blocked checkout never
     * leaves an earlier rule's slot durably consumed. This is independent of
     * (and faster than) the order-status-transition cleanup in
     * release_reserved_usage() / release_stale_promo_reservations(), which
     * only fires later if/when the order itself reaches a terminal status.
     *
     * Idempotent via the order's own '_drw_promos_reserved' meta flag: without
     * it, a genuine re-fire of this hook for the same order (e.g. a Store API
     * pay-for-order retry through CheckoutOrder.php, which fires the same
     * 'woocommerce_store_api_checkout_order_processed' action again) would hit
     * try_reserve_usage()'s UNIQUE(order_id, rule_id) collision (a safe no-op
     * for the FIRST call) and misreport it as a fresh denial on every retry.
     *
     * @param int            $order_id     Order ID passed by the hook.
     * @param array          $posted_data  Posted checkout data (unused; the
     *                                     order object is the source of truth).
     * @param \WC_Order|null $order        Order object passed by the hook.
     * @throws \Exception When a limit_user hard cap denies the reservation.
     */
    public function reserve_promo_usage($order_id, $posted_data, $order)
    {
        if (!($order instanceof \WC_Order)) {
            $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        }
        if (!$order) {
            return;
        }

        // IDENTITY DOCUMENT / FIRST-PURCHASE VERIFICATION -- runs BEFORE the
        // '_drw_promos_reserved' idempotency short-circuit below and BEFORE
        // the promo_ids/rule_ids resolution, deliberately: a popup-minted
        // welcome coupon has no compiled wp_drw_rules row at all (it isn't a
        // wp_drw_promos row either -- see PopupCouponBridge's class
        // docblock), so it would never be found by resolve_order_promo_ids()/
        // resolve_order_rule_ids() and would silently skip the
        // "empty($promo_ids) && empty($rule_ids)" early-return path below,
        // never reaching this check if it were placed any later. See
        // enforce_first_purchase_welcome_coupon() for the full policy.
        $this->enforce_first_purchase_welcome_coupon($order);

        if ($order->get_meta('_drw_promos_reserved')) {
            return;
        }

        $promo_ids = $this->resolve_order_promo_ids($order);
        $rule_ids  = $this->resolve_order_rule_ids($order);
        if (empty($promo_ids) && empty($rule_ids)) {
            $order->update_meta_data('_drw_promos_reserved', 1);
            $order->save();
            return;
        }

        $customer_key = \Drw\App\Models\CustomerIdentity::resolve_from_order($order);

        // No identity to key a per-customer reservation on at all (should be
        // rare — order creation normally requires a billing email/account).
        // Nothing enforceable without one; flag reserved so this order is
        // never re-attempted, and let the bare usage_limit path (already
        // soft) be the only signal for these.
        if (null === $customer_key) {
            $order->update_meta_data('_drw_promos_reserved', 1);
            $order->save();
            return;
        }

        // Rule ids successfully reserved by THIS call, in commit order — see
        // the "COMPENSATING ROLLBACK" docblock note above.
        $reserved_this_call = [];

        try {
            foreach ($promo_ids as $pid) {
                $promo = \Drw\App\Models\PromoModel::get_promo($pid);
                if (!$promo) {
                    continue;
                }

                $rule_id = !empty($promo['rule_id']) ? (int)$promo['rule_id'] : 0;
                if ($rule_id <= 0) {
                    // Vía A (coupon) promo — nothing compiled to reserve against.
                    continue;
                }

                $rule = \Drw\App\Models\RuleModel::get_rule($rule_id);
                if (!$rule) {
                    continue;
                }

                if ($this->attempt_rule_reservation($rule, $rule_id, $customer_key, $order_id, $pid, $promo)) {
                    $reserved_this_call[] = $rule_id;
                }
            }

            // Same reservation attempt for a manually-authored rule's own
            // usage_limit/limit_user — see resolve_order_rule_ids() for how these
            // ids are resolved. $pid/$promo are null: there is no promo row to
            // attribute the redemption to, only the rule itself.
            foreach ($rule_ids as $rule_id) {
                $rule = \Drw\App\Models\RuleModel::get_rule($rule_id);
                if (!$rule) {
                    continue;
                }

                if ($this->attempt_rule_reservation($rule, $rule_id, $customer_key, $order_id, null, null)) {
                    $reserved_this_call[] = $rule_id;
                }
            }
        } catch (\Exception $e) {
            foreach ($reserved_this_call as $reserved_rule_id) {
                \Drw\App\Models\RuleModel::release_usage($reserved_rule_id, $order_id);
            }
            throw $e;
        }

        $order->update_meta_data('_drw_promos_reserved', 1);
        $order->save();
    }

    /**
     * IDENTITY DOCUMENT / FIRST-PURCHASE VERIFICATION -- anti-fraud
     * extension of reserve_promo_usage(). If $order applies at least one
     * "welcome"-type coupon (see order_has_welcome_coupon()), blocks
     * checkout when ANY OTHER order already exists carrying
     * '_drw_welcome_coupon_used' (set by track_promo_redemptions() once a
     * welcome coupon is genuinely redeemed on a PAID order) OR
     * '_drw_welcome_coupon_reserved' (set by THIS method, below, the moment
     * an order carrying a welcome coupon is created -- see
     * mark_welcome_coupon_reserved()) whose billing email OR
     * '_billing_documento_identidad' matches THIS order's. Applies to ANY
     * welcome-type coupon -- popup-generated or a merchant-authored promo of
     * type 'welcome' created by hand in the Promos wizard -- both are
     * conceptually the same "first purchase" benefit for the customer
     * (plan's explicit scope note).
     *
     * ROUND-5 AUDIT FIX (TOCTOU bypass, confirmed exploitable): this method
     * runs at ORDER-CREATION time (woocommerce_checkout_order_processed /
     * woocommerce_store_api_checkout_order_processed, BEFORE any payment
     * gateway processes the order), but '_drw_welcome_coupon_used' is only
     * ever written by track_promo_redemptions(), hooked to
     * woocommerce_order_status_processing/_completed -- i.e. only AFTER
     * payment confirmation. For any delayed-confirmation payment method
     * (BACS/COD staying "on-hold"/"pending", or simply the real, if short,
     * window every gateway has between order-creation and payment
     * confirmation), a customer could previously place order 1 with welcome
     * coupon A, then -- before order 1 transitions to processing -- place
     * order 2 with welcome coupon B using the same document/email: order 2's
     * check would find no PRIOR '_drw_welcome_coupon_used' order yet (order
     * 1 hadn't been counted) and let it through, and both orders would later
     * get marked once paid. Fixed by ALSO checking (and, below, stamping) a
     * separate PROVISIONAL '_drw_welcome_coupon_reserved' mark the instant
     * an order carrying a welcome coupon is created -- closing the same
     * class of race reserve_promo_usage() already closes for rule/promo
     * usage limits (RuleModel::try_reserve_usage()), just as a single
     * order-meta flag instead of a counted table row, since a welcome
     * coupon's "one per identity" cap has no shared numeric limit to
     * decrement. The reservation is released (see release_reserved_usage()/
     * release_stale_welcome_coupon_reservations() below) if the order never
     * actually gets paid, so an abandoned/cancelled/failed order can never
     * permanently lock a customer's real identity out of a genuine first
     * purchase.
     *
     * A no-op for the vast majority of checkouts: order_has_welcome_coupon()
     * short-circuits before this method ever queries wc_get_orders().
     *
     * Message is DELIBERATELY generic and identical regardless of whether
     * the match came from the document or the email, or from a confirmed vs.
     * a merely-reserved prior order (plan requirement) -- revealing any of
     * that would let an attacker use the checkout error itself as an oracle.
     * The SAME message/exception is also thrown on real lock contention
     * below (round-7 audit fix) so a timing/oracle attacker cannot tell
     * "blocked by a genuine prior redemption" apart from "blocked because
     * another concurrent request for this identity is in flight".
     *
     * Same hard-block exception pattern (RouteException when Store API is
     * loaded, else a plain \Exception) already used by
     * attempt_rule_reservation()'s limit_user hard block above -- see that
     * method's docblock for the verified WooCommerce core exception-handling
     * paths shared by both the classic and Store API/Blocks checkout flows.
     *
     * ROUND-7 AUDIT FIX (tight order-creation-time TOCTOU race, confirmed
     * exploitable): the round-5 fix above closes the WIDE race between
     * order-creation and payment-confirmation by checking/stamping
     * '_drw_welcome_coupon_reserved' at creation time instead of waiting for
     * '_drw_welcome_coupon_used'. But the check
     * (prior_welcome_coupon_redemption_exists()) and the stamp
     * (mark_welcome_coupon_reserved()) below are still two separate steps
     * with no lock between them -- two checkout requests for the SAME
     * document/email, created within the same tens-of-milliseconds window
     * (two tabs, two scripted requests), can both pass the SELECT before
     * either commits its stamp, and both proceed. Closed here the same way
     * track_promo_redemptions() (above) closes its own double-count race:
     * real MySQL GET_LOCK() mutexes, one per non-empty identity signal
     * (document, email) so two requests sharing EITHER signal serialize,
     * acquired in a fixed order (document before email) so two callers can
     * never deadlock waiting on each other's locks in reverse order.
     *
     * Unlike track_promo_redemptions() (safe to just bail on contention --
     * the lock holder is already doing that exact counting work), bailing
     * here would mean silently SKIPPING the anti-fraud check and letting
     * checkout proceed unguarded -- the opposite of what this method exists
     * for. So real contention (GET_LOCK() reports the literal 0, i.e. a
     * concurrent request for this exact identity is provably in flight right
     * now) is treated as a hard block, using the SAME generic exception as a
     * genuine prior-redemption match. Degrades to the pre-existing
     * unprotected check (same as before this fix) only when GET_LOCK()
     * itself is unavailable/unsupported (NULL, not a real timeout) --
     * consistent with this class's established graceful-degradation
     * philosophy elsewhere.
     *
     * @param \WC_Order $order
     * @throws \Exception When a prior welcome-coupon redemption (confirmed
     *                     OR still-reserved) is found for this order's
     *                     billing email or identity document, or when real
     *                     lock contention proves a concurrent request for
     *                     the same identity is in flight.
     */
    private function enforce_first_purchase_welcome_coupon($order)
    {
        if (!$this->order_has_welcome_coupon($order)) {
            return;
        }

        $order_id = (int)$order->get_id();
        $email    = method_exists($order, 'get_billing_email') ? trim((string)$order->get_billing_email()) : '';
        $document = trim((string)$order->get_meta('_billing_documento_identidad'));

        if ('' === $email && '' === $document) {
            // Nothing to key the cross-check on at all (should be rare --
            // the checkout field is required, and get_billing_email() is
            // normally required for checkout to even reach this point).
            // Nothing enforceable without an identity signal; fail open
            // rather than block a legitimate customer on missing data this
            // method has no way to produce itself.
            return;
        }

        global $wpdb;
        $has_wpdb_locks = isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var') && method_exists($wpdb, 'prepare');

        // Fixed acquisition order (document, then email) across every call
        // so two concurrent requests can never deadlock on each other's
        // locks. Only non-empty signals get a lock -- see this method's
        // docblock (round-7 audit fix) for why serializing on EITHER signal
        // is required to close the race.
        $lock_names = [];
        if ('' !== $document) {
            $lock_names[] = 'drw_welcome_doc_' . md5($document);
        }
        if ('' !== $email) {
            $lock_names[] = 'drw_welcome_email_' . md5($email);
        }

        $acquired_locks = [];

        try {
            foreach ($lock_names as $lock_name) {
                $lock_result = $has_wpdb_locks
                    ? $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 10))
                    : null;

                // A literal 0 means another connection genuinely holds this
                // identity's lock right now -- a concurrent request for the
                // SAME document or email is in flight. Fail closed (see
                // docblock) rather than proceed unprotected.
                if ($lock_result === 0 || $lock_result === '0') {
                    $this->deny_first_purchase();
                }

                if ($lock_result === 1 || $lock_result === '1') {
                    $acquired_locks[] = $lock_name;
                }
                // NULL (degraded/unsupported environment) -- no real mutex
                // for this key, fall through to the unprotected check below
                // exactly as before this fix.
            }

            // Reconnect guard (same technique/rationale as
            // track_promo_redemptions()'s "round-10 audit finding", applied
            // here too for consistency): GET_LOCK()'s named lock is scoped to
            // the MySQL SESSION that acquired it. Fingerprinted HERE, right
            // after acquisition and before any further query runs (mirroring
            // where track_promo_redemptions() captures its own fingerprint) --
            // capturing and checking it at the same point would never be able
            // to observe a reconnect that happens during the work in between.
            // A NULL fingerprint (no real lock was ever acquired, e.g. a
            // fully degraded environment) means there is no mutex to lose.
            $lock_conn_id = !empty($acquired_locks) ? self::wpdb_connection_fingerprint($wpdb) : null;

            if ($this->prior_welcome_coupon_redemption_exists($order_id, $document, $email)) {
                $this->deny_first_purchase();
            }

            // Checked right before the write below (not earlier): if $wpdb
            // reconnected mid-method (its own "MySQL server has gone away"
            // recovery) between acquiring the lock(s) above and here, MySQL
            // auto-releases the lock the instant the old session drops, and
            // a concurrent request could then acquire it and run unprotected
            // while this call keeps going as if still serialized. Unlike
            // track_promo_redemptions() (safe to just bail there -- the new
            // lock holder redoes the same work), silently proceeding here
            // would mean stamping the reservation without the mutex that is
            // supposed to make this atomic -- so a detected reconnect is
            // treated the same as real lock contention: fail closed via
            // deny_first_purchase().
            if (!empty($acquired_locks) && !self::wpdb_connection_still_open($wpdb, $lock_conn_id)) {
                $this->deny_first_purchase();
            }

            // Passed the check -- stamp the provisional reservation IMMEDIATELY
            // (round-5 audit fix, see this method's docblock) so that any OTHER
            // order created for the same email/document from this instant
            // onward, even a fraction of a second later and long before this
            // order is ever paid, sees it via prior_welcome_coupon_redemption_exists().
            // Still inside the lock(s) acquired above (round-7 audit fix) so
            // this check-then-stamp sequence is atomic w.r.t. any other
            // request for the same identity.
            $this->mark_welcome_coupon_reserved($order);
        } finally {
            if ($has_wpdb_locks) {
                foreach (array_reverse($acquired_locks) as $lock_name) {
                    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
                }
            }
        }
    }

    /**
     * Throws the single, generic "first purchase only" block used by
     * enforce_first_purchase_welcome_coupon() for BOTH a genuine
     * prior-redemption match and real lock contention (round-7 audit fix) --
     * a shared helper so the two call sites can never drift into two
     * differently-worded exceptions that would themselves become an oracle.
     * See enforce_first_purchase_welcome_coupon()'s docblock for the full
     * rationale.
     *
     * @throws \Exception Always.
     */
    private function deny_first_purchase()
    {
        $message = __('Este código promocional es válido únicamente para tu primera compra.', 'discount-rules-woo');

        if (class_exists('\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException')) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                'drw_welcome_coupon_first_purchase_only',
                $message,
                400
            );
        }

        throw new \Exception($message);
    }

    /**
     * Stamp the provisional '_drw_welcome_coupon_reserved' mark used by
     * enforce_first_purchase_welcome_coupon()'s prior-redemption cross-check
     * (round-5 audit fix -- see that method's docblock for the TOCTOU race
     * this closes). Deliberately a SEPARATE meta key from
     * '_drw_welcome_coupon_used' (track_promo_redemptions(), payment-
     * confirmed, permanent) rather than reusing/pre-setting that one: a
     * reservation must be releasable if the order never gets paid
     * (release_reserved_usage()/release_stale_welcome_coupon_reservations()
     * below), while '_drw_welcome_coupon_used' is intentionally permanent
     * once set. Persisted immediately via its own save() -- this method is
     * called from a point in reserve_promo_usage()'s call chain that can
     * still return early (e.g. the pre-existing '_drw_promos_reserved'
     * idempotency short-circuit) before that method's own later save(),
     * so this cannot ride along on it.
     *
     * Idempotent: a no-op if this exact order already carries the mark
     * (e.g. a Store API pay-for-order retry re-firing the same hook for the
     * same order -- see reserve_promo_usage()'s docblock on why this whole
     * check deliberately runs on every call, not just the first).
     *
     * @param \WC_Order $order
     */
    private function mark_welcome_coupon_reserved($order)
    {
        if ($order->get_meta('_drw_welcome_coupon_reserved')) {
            return;
        }

        $order->update_meta_data('_drw_welcome_coupon_reserved', 1);
        $order->save();
    }

    /**
     * Whether $order has at least one applied coupon that is a
     * "welcome"-type coupon:
     *   - popup-minted, identified by the coupon's own '_drw_popup_submission_id'
     *     meta (stamped by PopupCouponBridge::compile_coupon() at mint time --
     *     that class is a separate, self-contained subsystem, never touched
     *     here); or
     *   - a merchant-authored Vía A (code-based) promo compiled by
     *     PromoBridgeController::compile_coupon(), identified by the
     *     coupon's own '_drw_promo_id' meta resolving to a wp_drw_promos row
     *     whose 'type' column is 'welcome' (PromoTypeRegistry's 'welcome'
     *     id -- see that registry, not modified here).
     *
     * Deliberately reads the underlying WC_Coupon post's OWN meta rather
     * than resolve_order_promo_ids() (which only matches a coupon back to a
     * promo via wp_drw_promos.code -- a popup-minted coupon has no such row
     * at all, so it would never be found that way).
     *
     * Guarded by function_exists()/class_exists() so this is a safe no-op
     * outside a real WooCommerce runtime (e.g. this plugin's standalone
     * tests/*.php suite, which never loads WC_Coupon).
     *
     * @param \WC_Order $order
     * @return bool
     */
    private function order_has_welcome_coupon($order)
    {
        if (!function_exists('wc_get_coupon_id_by_code') || !class_exists('WC_Coupon')) {
            return false;
        }

        foreach ($order->get_coupon_codes() as $code) {
            $coupon_id = (int)wc_get_coupon_id_by_code((string)$code);
            if ($coupon_id <= 0) {
                continue;
            }

            try {
                $coupon = new \WC_Coupon($coupon_id);
            } catch (\Throwable $e) {
                continue;
            }

            if ($coupon->get_meta('_drw_popup_submission_id')) {
                return true;
            }

            $promo_id = (int)$coupon->get_meta('_drw_promo_id');
            if ($promo_id > 0) {
                $promo = \Drw\App\Models\PromoModel::get_promo($promo_id);
                if ($promo && !empty($promo['type']) && 'welcome' === $promo['type']) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether any order OTHER than $order_id already carries
     * '_drw_welcome_coupon_used' (payment-confirmed, permanent) OR
     * '_drw_welcome_coupon_reserved' (round-5 audit fix -- provisional,
     * stamped at order-creation time and released if the order never gets
     * paid, see mark_welcome_coupon_reserved()/release_reserved_usage()/
     * release_stale_welcome_coupon_reservations()) with a matching billing
     * email OR a matching '_billing_documento_identidad' -- see
     * enforce_first_purchase_welcome_coupon() for why BOTH marks must be
     * checked here, not just the confirmed one (TOCTOU race between two
     * orders created before either is paid).
     *
     * Two separate wc_get_orders() calls (HPOS-compatible, mirrors
     * Conditions/PurchaseHistory.php's existing query pattern -- that file
     * is frozen and is NOT modified here) rather than a single OR'd
     * meta_query: 'billing_email' is a native WC_Order_Query arg that works
     * identically on HPOS and legacy post storage, while
     * '_billing_documento_identidad' is this plugin's own custom order
     * meta, only queryable via meta_query. Mixing the two into one OR'd
     * meta_query clause would require assuming 'billing_email' is itself
     * backed by a plain meta row, which is NOT guaranteed under HPOS (it is
     * a first-class column on wp_wc_orders) -- querying twice and OR-ing the
     * boolean outcome sidesteps that assumption entirely.
     *
     * Deliberately does NOT short-circuit/return early on a document match
     * (round-2 security audit "Gap A"): both queries that have a non-empty
     * signal to search on are always run before this method returns, so the
     * response TIME of enforce_first_purchase_welcome_coupon()'s caller
     * (checkout) never differs between "blocked by document", "blocked by
     * email", and "not blocked" purely as a function of query COUNT. This
     * is the same generic-message, no-oracle discipline the plan already
     * requires of the thrown exception's text, extended to timing -- a
     * patient attacker instrumenting real checkout attempts must not be
     * able to infer WHICH signal (a specific identity document is
     * especially sensitive PII) caused a block just by measuring latency.
     *
     * @param int    $order_id Current order id, excluded from the search.
     * @param string $document
     * @param string $email
     * @return bool
     */
    private function prior_welcome_coupon_redemption_exists($order_id, $document, $email)
    {
        if (!function_exists('wc_get_orders')) {
            return false;
        }

        $found = false;

        // Shared OR clause (round-5 audit fix): matches either the
        // payment-confirmed mark OR the still-in-flight reservation --
        // see this method's own docblock for why both must count.
        $used_or_reserved = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            'relation' => 'OR',
            ['key' => '_drw_welcome_coupon_used', 'value' => '1'],
            ['key' => '_drw_welcome_coupon_reserved', 'value' => '1'],
        ];

        if ('' !== $document) {
            $by_document = wc_get_orders([
                'exclude'    => [(int)$order_id],
                'limit'      => 1,
                'return'     => 'ids',
                'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                    'relation' => 'AND',
                    $used_or_reserved,
                    ['key' => '_billing_documento_identidad', 'value' => $document],
                ],
            ]);
            if (!empty($by_document)) {
                $found = true;
            }
        }

        if ('' !== $email) {
            $by_email = wc_get_orders([
                'exclude'        => [(int)$order_id],
                'billing_email'  => $email,
                'limit'          => 1,
                'return'         => 'ids',
                'meta_query'     => $used_or_reserved, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            ]);
            if (!empty($by_email)) {
                $found = true;
            }
        }

        return $found;
    }

    /**
     * Attempt to reserve one usage slot for a SINGLE rule against an order,
     * applying reserve_promo_usage()'s documented hard-block-vs-soft-fail
     * policy. Extracted so BOTH the Vía B (promo-backed, $pid/$promo set) and
     * manually-authored ($pid/$promo null) loops in reserve_promo_usage()
     * above share the exact same denial handling and can never drift apart —
     * see that method's docblock for the full policy rationale and the
     * guest-email-disclosure-oracle note on the hard-block branch below.
     *
     * Returns a bool telling the caller whether a NEW reservation slot was
     * actually created for this rule on THIS call (true), as opposed to
     * nothing being reserved (limit-less rule, denial, or an
     * already-held/idempotent no-op) — reserve_promo_usage() uses this to
     * know exactly which rule_ids it must compensate-release if a LATER
     * rule in the same order hard-blocks checkout (round-8 audit finding:
     * cross-rule reservation griefing, see reserve_promo_usage()).
     *
     * @param array      $rule         RuleModel-formatted rule row.
     * @param int        $rule_id
     * @param string     $customer_key
     * @param int        $order_id
     * @param int|null   $pid          Promo id, or null for a manually-authored rule.
     * @param array|null $promo        Promo row (used only for its 'name' title
     *                                 fallback), or null for a manually-authored rule.
     * @return bool True if a new slot was reserved by THIS call.
     * @throws \Exception When a limit_user hard cap denies the reservation.
     */
    private function attempt_rule_reservation($rule, $rule_id, $customer_key, $order_id, $pid, $promo)
    {
        $has_limit_user  = !empty($rule['limit_user']);
        $has_usage_limit = !empty($rule['usage_limit']);
        if (!$has_limit_user && !$has_usage_limit) {
            // No real limit configured on this rule: nothing to reserve.
            return false;
        }

        $reserved = \Drw\App\Models\RuleModel::try_reserve_usage($rule_id, $customer_key, $order_id, $pid);
        if ($reserved) {
            return true;
        }

        // try_reserve_usage() returns false BOTH when the slot is genuinely
        // denied AND when this exact (order_id, rule_id) pair already holds
        // a reservation (the UNIQUE(order_id, rule_id) collision is treated
        // as a safe no-op, per its own docblock). Those two cases must be
        // told apart here: a Store API checkout draft order is re-POSTed to
        // /checkout on every retry (same order_id each time), so if an
        // EARLIER rule in the caller's loop already reserved successfully and
        // a LATER one then blocks checkout, the customer's next retry
        // re-enters reserve_promo_usage() for the SAME order — without this
        // check the earlier rule's now-legitimate reservation would collide
        // with itself and be misreported as a fresh denial.
        if ($this->order_already_holds_reservation($order_id, $rule_id)) {
            return false;
        }

        // try_reserve_usage() collapses THREE distinct denial causes into
        // one boolean: the rule's global usage_limit exhausted, THIS
        // customer's own limit_user exhausted, or a transient DB error
        // inside the transaction. Only the middle case is safe to surface
        // to the customer as "your account already used this" — the
        // other two, if worded that way, become a scriptable oracle for
        // probing a promo's global remaining inventory with a fresh/
        // never-used email (a first-time customer would get an
        // "account limit" message that is simply false), and would
        // wrongly hard-block a legitimate customer on a transient error.
        // Disambiguate with a read-only recheck: this doesn't reopen the
        // reservation race (the atomic decision already happened inside
        // try_reserve_usage() above) — it only decides which message,
        // if any, to show.
        //
        // NOTE (round-8 audit finding, evaluated and deliberately NOT
        // changed here): a bare usage_limit-only denial stays soft-fail
        // below rather than also hard-blocking checkout. This is the exact,
        // already-considered tradeoff documented where this method is
        // called from (see reserve_promo_usage()'s docblock) and is pinned
        // down by an explicit regression assertion — test
        // tests/test-cartcontroller-promo-reservation.php case (d): "A bare
        // usage_limit denial (no limit_user) must NOT throw (soft-fail,
        // matches pre-existing behavior)." Promoting it to a hard block is a
        // real behavior change to checkout (an order can now fail at the
        // exact moment a limited promo's global cap is hit) that a prior
        // audit round explicitly flagged as needing product/orchestrator
        // sign-off before changing, not something to flip silently inside a
        // fix pass on checkout-path-critical code — left as-is pending that
        // explicit decision.
        $customer_exhausted = false;
        if ($has_limit_user) {
            $fresh_rule = \Drw\App\Models\RuleModel::get_rule($rule_id);
            if ($fresh_rule) {
                $customer_count = \Drw\App\Models\RuleModel::customer_redemption_count($rule_id, $customer_key);
                $customer_exhausted = $customer_count >= (int)$fresh_rule['limit_user'];
            }
        }

        if ($customer_exhausted) {
            // SECURITY NOTE (defense-in-depth, not a full fix): the
            // customer_key this hard-block is keyed on comes from
            // CustomerIdentity::resolve_from_order(), which for a guest
            // checkout is nothing but the UNVERIFIED billing_email the
            // current request typed in. That means an unauthenticated
            // caller can script repeated checkout submissions with
            // arbitrary emails and use "checkout blocked with this
            // specific message" as an oracle for "has this email already
            // redeemed this limit_user promo". This is the exact same
            // tradeoff WooCommerce core itself accepts for coupon
            // usage_limit_per_user against guest billing emails, and
            // cannot be closed purely in code without a product decision
            // (e.g. requiring a verified account before a limit_user
            // promo is honored) that is out of scope for this fix and
            // needs explicit sign-off. What CAN be done here: cap how
            // often a single IP can receive the SPECIFIC "already used by
            // your account" wording, consistent with this codebase's
            // existing RateLimiter defense-in-depth pattern (see
            // PromosController's check-code/check-conflicts endpoints).
            // Checkout still blocks either way (try_reserve_usage()'s
            // atomic DB-level denial above is untouched and remains
            // authoritative — this only changes which message is shown),
            // so there is no enforcement bypass here, only a per-IP/per-
            // window cap on how often the SPECIFIC wording is shown — the
            // underlying blocked-vs-not-blocked signal itself remains
            // observable on every attempt regardless of the rate limit
            // (see round-3 audit finding: closing that fully needs the
            // product decision above, not just message-wording throttling).
            // Two independent buckets, BOTH must be under their cap for the
            // specific wording to show:
            //  - by IP: cheap, but WC_Geolocation::get_ip_address() trusts
            //    client-supplied X-Forwarded-For/X-Real-IP with no
            //    trusted-proxy allow-list, so a scripted attacker can send a
            //    fresh spoofed value per request and get a brand-new bucket
            //    every time -- see round-4 audit finding.
            //  - by target (customer_key + rule_id): NOT spoofable via
            //    headers, since it keys on the very thing being probed
            //    ("has this email already used this promo") rather than on
            //    caller-supplied network metadata. This caps how many times
            //    the specific wording can be shown for one target regardless
            //    of how many different (real or spoofed) IPs the attempts
            //    come from, closing the unlimited-rate oracle the IP-only
            //    bucket alone left open, without needing any reverse-proxy/
            //    infrastructure trust assumptions this plugin can't verify.
            $prober_ip = self::get_client_ip();
            $allow_by_ip = RateLimiter::check(
                'promo-limit-disclosure:' . md5($prober_ip),
                10,
                900
            );
            $allow_by_target = RateLimiter::check(
                'promo-limit-disclosure-target:' . md5($customer_key . '|' . $rule_id),
                10,
                900
            );
            $allow_specific_message = $allow_by_ip && $allow_by_target;

            $title = !empty($rule['title']) ? $rule['title'] : (!empty($promo['name']) ? $promo['name'] : '');
            $message = $allow_specific_message
                ? sprintf(
                    /* translators: %s: discount/promo title */
                    __('El descuento "%s" ya alcanzó el límite de usos permitido para tu cuenta.', 'discount-rules-woo'),
                    $title
                )
                : __('No fue posible aplicar uno de los descuentos a tu pedido. Por favor, inténtalo de nuevo.', 'discount-rules-woo');

            // Prefer a Store API RouteException (clean 400 error_code +
            // message) when the class is loaded; falls back to a plain
            // \Exception for the classic checkout flow / any WooCommerce
            // version without the Store API loaded. RouteException IS-A
            // \Exception, so the classic catch (Exception $e) block in
            // WC_Checkout::process_checkout() handles either one
            // identically.
            //
            // SECURITY: the machine-readable $error_code below must be
            // throttled in lockstep with $message above. The Store API
            // surfaces RouteException::getErrorCode() verbatim as the
            // top-level JSON `code` field of the error response, so if this
            // stayed a constant 'drw_promo_usage_limit_reached' regardless
            // of $allow_specific_message, an attacker could script
            // unlimited checkout attempts and read that code field alone
            // (bypassing the RateLimiter above entirely) to learn whether a
            // candidate email already redeemed this limit_user promo --
            // exactly the oracle the message throttling exists to close.
            // When the specific wording isn't allowed, the error_code must
            // be equally generic so it carries no more signal than the text.
            if (class_exists('\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException')) {
                $error_code = $allow_specific_message
                    ? 'drw_promo_usage_limit_reached'
                    : 'drw_checkout_error';
                throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                    $error_code,
                    $message,
                    400
                );
            }

            throw new \Exception($message);
        }

        // Global usage_limit exhaustion, or a transient error inside
        // try_reserve_usage() -- soft-fail only, never disclosed to the
        // customer (see docblock above).
        error_log(sprintf(
            '[discount-rules-woo] Rule #%d usage_limit reached (or a transient reservation error occurred) at reservation time for order #%d (soft, not blocking checkout).',
            $rule_id,
            (int)$order_id
        ));
        return false;
    }

    /**
     * Best-effort client IP for the RateLimiter bucket used by
     * reserve_promo_usage()'s guest-email disclosure throttle.
     *
     * ROUND-7 AUDIT FIX: this used to prefer WC_Geolocation::get_ip_address(),
     * on the assumption that it "already handles trusted proxy headers per
     * this site's config". Verified against the real installed WooCommerce
     * core (class-wc-geolocation.php) that this is FALSE: that method
     * unconditionally trusts $_SERVER['HTTP_X_REAL_IP'] and
     * 'HTTP_X_FORWARDED_FOR' ahead of REMOTE_ADDR, with no trusted-proxy
     * allowlist at all. A caller can therefore send a different (even
     * fabricated) X-Forwarded-For value on every single request and get a
     * brand-new rate-limit bucket key each time -- NOT "sharing a bucket
     * with other unidentifiable callers" as the old docblock claimed, but a
     * fresh, unthrottled bucket per request, fully defeating this limiter
     * with zero proxies/botnet required. This plugin has no trusted-proxy
     * allowlist setting of its own, so the only value that cannot be forged
     * by the client is $_SERVER['REMOTE_ADDR'] itself (the actual TCP peer
     * WordPress/PHP saw) -- used directly, ignoring WC_Geolocation's
     * header-trusting logic for this security-sensitive bucket key. (A real
     * reverse-proxy deployment in front of this site would need its own
     * trusted-proxy configuration to recover per-visitor granularity here --
     * out of scope for this fix; REMOTE_ADDR is still strictly safer than a
     * client-forgeable header.)
     *
     * @return string
     */
    private static function get_client_ip()
    {
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
    }

    /**
     * Whether THIS exact order already holds a reservation (or confirmation)
     * for a given rule. Used by reserve_promo_usage() to tell a genuine
     * try_reserve_usage() denial apart from a harmless self-collision on
     * retry — see the call site for the full explanation.
     *
     * @param int $order_id
     * @param int $rule_id
     * @return bool
     */
    private function order_already_holds_reservation($order_id, $rule_id)
    {
        global $wpdb;
        $redemptions_table = $wpdb->prefix . 'drw_promo_redemptions';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM $redemptions_table WHERE order_id = %d AND rule_id = %d AND status IN ('reserved','confirmed') LIMIT 1",
            (int)$order_id,
            (int)$rule_id
        ));

        return !empty($exists);
    }

    /**
     * Release every promo/rule usage reservation held by an order that just
     * became definitively unpayable, hooked to BOTH
     * woocommerce_order_status_cancelled and woocommerce_order_status_failed
     * (same dual-hook style as the track_promo_redemptions() registration).
     *
     * Idempotent: RuleModel::release_usage() is itself a safe no-op once a
     * reservation has already been released (or never existed), so this can
     * fire any number of times for the same order without double-decrementing
     * used_count.
     *
     * @param int            $order_id Order ID passed by the status hook.
     * @param \WC_Order|null $order    Order object passed by the status hook.
     */
    public function release_reserved_usage($order_id, $order = null)
    {
        // $order is only ever used below as an existence gate ("is there a
        // real order to release for"); the actual release query is scoped by
        // the raw $order_id parameter. Both of this method's hook
        // registrations (woocommerce_order_status_cancelled/_failed) always
        // pass a matched ($order_id, $order) pair straight from
        // WooCommerce's own status-transition action, so this mismatch can't
        // currently happen -- but don't let a mismatched pair from any other
        // caller silently gate on the wrong order's existence. Re-resolve by
        // $order_id instead of trusting a non-matching $order.
        if ($order instanceof \WC_Order && (int) $order->get_id() !== (int) $order_id) {
            $order = null;
        }
        if (!($order instanceof \WC_Order)) {
            $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        }
        if (!$order) {
            return;
        }

        global $wpdb;
        $redemptions_table = $wpdb->prefix . 'drw_promo_redemptions';

        $rule_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT rule_id FROM $redemptions_table WHERE order_id = %d",
            (int)$order_id
        ));

        foreach ($rule_ids as $rule_id) {
            \Drw\App\Models\RuleModel::release_usage((int)$rule_id, (int)$order_id);
        }

        // IDENTITY DOCUMENT / FIRST-PURCHASE VERIFICATION (round-5 audit
        // fix): this order is now definitively unpayable, so its provisional
        // welcome-coupon reservation (mark_welcome_coupon_reserved(), stamped
        // at order-creation time to close the TOCTOU race -- see
        // enforce_first_purchase_welcome_coupon()'s docblock) must not keep
        // blocking a genuinely different first purchase by the same email/
        // document forever. Only ever clears the RESERVATION mark, never
        // '_drw_welcome_coupon_used' -- that one is written exclusively by
        // track_promo_redemptions() once a welcome coupon is genuinely
        // confirmed on a PAID order, and is intentionally permanent.
        if ($order->get_meta('_drw_welcome_coupon_reserved')) {
            $order->delete_meta_data('_drw_welcome_coupon_reserved');
            $order->save();
        }
    }

    /**
     * WP-Cron safety net (drw_release_stale_promo_reservations, scheduled
     * 'daily' — see Router::run_migrations()): release any redemption still
     * sitting in 'reserved' more than 48 hours after creation, independent of
     * this site's own WooCommerce hold-stock-time setting. Covers orders that
     * never reach a terminal cancelled/failed/processing/completed status at
     * all (e.g. abandoned pending payment) — release_reserved_usage() and
     * track_promo_redemptions()'s confirm_usage() call only ever fire on a real
     * status transition, so a reservation for an order that never transitions
     * again would otherwise hold its slot forever.
     *
     * Idempotent for the same reason release_reserved_usage() is: repeat runs
     * against an already-released row are safe no-ops.
     */
    public function release_stale_promo_reservations()
    {
        // IDENTITY DOCUMENT / FIRST-PURCHASE VERIFICATION (round-5 audit
        // fix): run first and unconditionally -- deliberately NOT gated on
        // the rule/promo reservation table having any stale rows below (this
        // welcome-coupon reservation lives in order meta, a completely
        // separate storage, and must be reaped even on a day where no rule
        // reservation happens to be stale).
        $this->release_stale_welcome_coupon_reservations();

        global $wpdb;
        $redemptions_table = $wpdb->prefix . 'drw_promo_redemptions';

        // Site-local "now" minus 48h, matching how every DATETIME column in
        // this plugin is written (current_time('mysql'), not the MySQL
        // server's own NOW()) — see PromoModel::get_home_promos() for the
        // same pattern.
        $cutoff = date('Y-m-d H:i:s', strtotime('-48 hours', strtotime(current_time('mysql'))));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT rule_id, order_id FROM $redemptions_table WHERE status = 'reserved' AND created_at < %s",
            $cutoff
        ), ARRAY_A);

        if (empty($rows)) {
            return;
        }

        foreach ($rows as $row) {
            // release_stale_usage(), NOT release_usage(): the SELECT above is
            // a snapshot of rows that were 'reserved' as of the query, but
            // this loop runs after it -- if the underlying order gets paid
            // (confirm_usage()'d to 'confirmed') by a concurrent request
            // before this row's turn, release_usage()'s DELETE would still
            // match 'confirmed' too and wrongly erase a legitimate paid
            // order's redemption. release_stale_usage() scopes its DELETE to
            // status = 'reserved' only, so it becomes a safe no-op against a
            // row that has since been confirmed. See RuleModel::release_stale_usage()
            // for the full explanation.
            \Drw\App\Models\RuleModel::release_stale_usage((int)$row['rule_id'], (int)$row['order_id']);
        }
    }

    /**
     * Part of the WP-Cron safety net above (round-5 audit fix): releases a
     * '_drw_welcome_coupon_reserved' mark (mark_welcome_coupon_reserved())
     * left behind by an order that never reaches ANY terminal status at all
     * -- neither paid (processing/completed, which leaves
     * '_drw_welcome_coupon_used' permanently set via track_promo_redemptions()
     * instead) nor cancelled/failed (which release_reserved_usage() above
     * already handles immediately via its own status-transition hooks). The
     * classic "abandoned pending payment, gateway never redirected back, no
     * status transition ever fires" case -- same scenario
     * release_stale_promo_reservations() exists for the rule-usage
     * reservation table, just for this order-meta-based reservation instead.
     * Same 48h cutoff for consistency with that method.
     *
     * Deliberately does NOT touch '_drw_welcome_coupon_used' -- that mark is
     * permanent by design. Only orders carrying the RESERVED mark WITHOUT
     * the USED mark are candidates: an order carrying both already reached
     * processing/completed (genuinely redeemed), so there is nothing to
     * release for it.
     *
     * Capped at 200 orders per run (matching this codebase's convention of
     * hard "just in case" ceilings on admin/cron batch operations, e.g.
     * PopupModel::EXPORT_LIMIT) -- a single daily cron run is not the place
     * for an unbounded query, and any remainder is simply picked up on the
     * next day's run.
     */
    private function release_stale_welcome_coupon_reservations()
    {
        if (!function_exists('wc_get_orders')) {
            return;
        }

        $cutoff = strtotime('-48 hours', strtotime(current_time('mysql')));

        $order_ids = wc_get_orders([
            'limit'        => 200,
            'return'       => 'ids',
            'date_created' => '<' . $cutoff,
            'meta_query'   => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                ['key' => '_drw_welcome_coupon_reserved', 'value' => '1'],
                ['key' => '_drw_welcome_coupon_used', 'compare' => 'NOT EXISTS'],
            ],
        ]);

        if (empty($order_ids) || !is_array($order_ids)) {
            return;
        }

        foreach ($order_ids as $order_id) {
            $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
            if (!$order) {
                continue;
            }

            $order->delete_meta_data('_drw_welcome_coupon_reserved');
            $order->save();
        }
    }

    /**
     * Modifies shipping package rates to set shipping rate cost to 0 when Free Shipping is unlocked.
     */
    public function modify_shipping_package_rates($rates, $package)
    {
        $cart = WC()->cart;
        if (!$cart) {
            return $rates;
        }

        $engine = \Drw\App\Controllers\RulesEngine::instance();
        $discounts = $engine->calculate_cart_level_discounts($cart);

        $free_shipping = !empty($discounts['free_shipping']);

        // SANDBOX MODE — additive free-shipping preview for a sandboxed
        // free_ship_threshold-type promo. Only evaluated when the REAL
        // engine hasn't already unlocked free shipping. No-op unless
        // get_sandbox_rule() resolved a valid, current-admin-owned override;
        // see that method for the full safety contract. Wrapped in
        // try/catch so a bug here can only ever affect the admin's OWN
        // preview cart, never fatal the request.
        if (!$free_shipping) {
            try {
                $sandbox_rule = $this->get_sandbox_rule();
                if (null !== $sandbox_rule && $engine->is_rule_matched($sandbox_rule, $cart)) {
                    $adjustments = !empty($sandbox_rule['adjustments']) ? (array)$sandbox_rule['adjustments'] : [];
                    if (!empty($adjustments['type']) && $adjustments['type'] === 'free_shipping') {
                        $shipping_engine = new \Drw\App\Adjustments\FreeShipping();
                        if ($shipping_engine->is_free_shipping_unlocked($adjustments, $cart)) {
                            $free_shipping = true;
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log('[discount-rules-woo] Sandbox preview (free shipping) failed: ' . $e->getMessage());
            }
        }

        if ($free_shipping) {
            foreach ($rates as $rate_key => $rate) {
                $rates[$rate_key]->cost = 0;
                $taxes = [];
                foreach ($rates[$rate_key]->taxes as $key => $tax) {
                    $taxes[$key] = 0;
                }
                $rates[$rate_key]->taxes = $taxes;
            }
        }

        return $rates;
    }

    /**
     * Integrates coupon matching filters to hook into WooCommerce core coupon operations for dynamic rules.
     */
    public function get_shop_coupon_data($data, $code)
    {
        if (empty($code)) {
            return $data;
        }

        // If the coupon already exists in the database, let WooCommerce handle it
        if ($data !== false && !empty($data)) {
            return $data;
        }

        $engine = \Drw\App\Controllers\RulesEngine::instance();
        $rules = $engine->get_active_rules();

        if (empty($rules)) {
            return $data;
        }

        $coupon_matched = false;
        foreach ($rules as $rule) {
            $conditions = !empty($rule['conditions']) ? (array)$rule['conditions'] : [];
            foreach ($conditions as $cond) {
                $type = !empty($cond['type']) ? $cond['type'] : '';
                if ($type === 'cart_coupon' || $type === 'coupon') {
                    $target_coupons = !empty($cond['value']) ? (array)$cond['value'] : [];
                    $target_coupons = array_map('strtolower', array_map('trim', $target_coupons));
                    if (in_array(strtolower($code), $target_coupons, true) && \Drw\App\Conditions\CartCoupon::is_schedule_matched($cond)) {
                        $coupon_matched = true;
                        break 2;
                    }
                }
            }
        }

        if ($coupon_matched) {
            return [
                'id'                         => 99999900 + mt_rand(1, 99),
                'code'                       => $code,
                'amount'                     => 0,
                'discount_type'              => 'fixed_cart',
                'individual_use'             => false,
                'product_ids'                => array(),
                'exclude_product_ids'        => array(),
                'usage_limit'                => '',
                'usage_limit_per_user'       => '',
                'limit_usage_to_x_items'     => '',
                'expiry_date'                => '',
                'free_shipping'              => false,
                'product_categories'         => array(),
                'exclude_product_categories' => array(),
                'exclude_sale_items'         => false,
                'minimum_amount'             => '',
                'maximum_amount'             => '',
                'customer_email'             => array(),
            ];
        }

        return $data;
    }

    /**
     * Formats cart item price.
     */
    public function format_cart_item_price($price_html, $cart_item, $cart_item_key)
    {
        if (!empty($cart_item['drw_discounted']) && isset($cart_item['drw_original_price']) && isset($cart_item['drw_discount_price'])) {
            $original = (float)$cart_item['drw_original_price'];
            $discounted = (float)$cart_item['drw_discount_price'];
            if ($original > $discounted) {
                $price_html = '<del>' . wc_price($original) . '</del> <ins>' . wc_price($discounted) . '</ins>';
            }
        }
        return $price_html;
    }

    /**
     * Formats cart item subtotal.
     */
    public function format_cart_item_subtotal($subtotal_html, $cart_item, $cart_item_key)
    {
        if (!empty($cart_item['drw_discounted']) && isset($cart_item['drw_original_price']) && isset($cart_item['drw_discount_price'])) {
            $qty = $cart_item['quantity'];
            $original_subtotal = (float)$cart_item['drw_original_price'] * $qty;
            $discounted_subtotal = (float)$cart_item['drw_discount_price'] * $qty;
            if ($original_subtotal > $discounted_subtotal) {
                $subtotal_html = '<del>' . wc_price($original_subtotal) . '</del> <ins>' . wc_price($discounted_subtotal) . '</ins>';
            }
        }
        return $subtotal_html;
    }

    /**
     * Formats cart totals order total html.
     */
    public function format_cart_totals_order_total_html($value)
    {
        $cart = WC()->cart;
        if ($cart) {
            $savings = $this->get_total_savings($cart);
            if ($savings > 0) {
                $value .= '<div class="drw-savings-message" style="font-size: 0.9em; color: #10b981; margin-top: 5px;">' . sprintf(__('You Saved: %s', 'discount-rules-woo'), wc_price($savings)) . '</div>';
            }
        }
        return $value;
    }

    /**
     * Formats formatted order total.
     */
    public function format_order_total($formatted_total, $order, $tax_display, $display_refunded)
    {
        $savings = $this->get_order_total_savings($order);
        if ($savings > 0) {
            $formatted_total .= '<div class="drw-savings-message" style="font-size: 0.9em; color: #10b981; margin-top: 5px;">' . sprintf(__('You Saved: %s', 'discount-rules-woo'), wc_price($savings)) . '</div>';
        }
        return $formatted_total;
    }

    /**
     * Formats order formatted line subtotal.
     */
    public function format_order_line_subtotal($subtotal, $item, $order)
    {
        $original = $item->get_meta('_drw_original_price', true);
        $discounted = $item->get_meta('_drw_discount_price', true);
        if ($original !== '' && $discounted !== '') {
            $original = (float)$original;
            $discounted = (float)$discounted;
            if ($original > $discounted) {
                $qty = (int)$item->get_quantity();
                $subtotal = '<del>' . wc_price($original * $qty) . '</del> <ins>' . wc_price($discounted * $qty) . '</ins>';
            }
        }
        return $subtotal;
    }

    /**
     * Displays admin order totals after total.
     */
    public function display_admin_order_totals_after_total($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $savings = $this->get_order_total_savings($order);
        if ($savings > 0) {
            ?>
            <tr>
                <td class="label"><?php _e('Total Saved:', 'discount-rules-woo'); ?></td>
                <td width="1%"></td>
                <td class="total" style="color: #10b981; font-weight: bold;">
                    <?php echo wc_price($savings); ?>
                </td>
            </tr>
            <?php
        }
    }

    /**
     * Helper to compute total cart savings.
     */
    private function get_total_savings($cart)
    {
        if (!$cart) {
            return 0.0;
        }

        $savings = 0.0;
        foreach ($cart->get_cart() as $cart_item) {
            if (!empty($cart_item['drw_discounted']) && isset($cart_item['drw_original_price']) && isset($cart_item['drw_discount_price'])) {
                $diff = (float)$cart_item['drw_original_price'] - (float)$cart_item['drw_discount_price'];
                if ($diff > 0) {
                    $savings += $diff * $cart_item['quantity'];
                }
            }
        }

        foreach ($cart->get_fees() as $fee) {
            if ($fee->amount < 0) {
                $savings += abs($fee->amount);
            }
        }

        return $savings;
    }

    /**
     * Helper to compute total order savings.
     */
    private function get_order_total_savings($order)
    {
        if (!$order) {
            return 0.0;
        }

        $savings = 0.0;
        foreach ($order->get_items() as $item) {
            $original = $item->get_meta('_drw_original_price', true);
            $discounted = $item->get_meta('_drw_discount_price', true);
            if ($original !== '' && $discounted !== '') {
                $diff = (float)$original - (float)$discounted;
                if ($diff > 0) {
                    $savings += $diff * (int)$item->get_quantity();
                }
            }
        }

        foreach ($order->get_fees() as $fee) {
            if ($fee->get_total() < 0) {
                $savings += abs($fee->get_total());
            }
        }

        return $savings;
    }

    /**
     * Check if product is in the products or categories lists.
     *
     * @param \WC_Product $product
     * @param array $product_ids
     * @param array $category_ids
     * @param bool $match_all_when_empty When both lists are empty: true (default)
     *   means "match every product" (used for buy-side scope, where an empty
     *   scope legitimately means storewide). Pass false for get-side matching
     *   where an empty list means "nothing was configured", so it must match
     *   nothing instead of everything -- mirrors Drw\App\Adjustments\Bogo::
     *   is_product_in_list()'s same parameter/fix.
     */
    private function is_product_in_list($product, $product_ids, $category_ids, $match_all_when_empty = true)
    {
        if (empty($product_ids) && empty($category_ids)) {
            return $match_all_when_empty;
        }
        
        $product_id = $product->get_id();
        $parent_id  = $product->get_parent_id();
        
        if (!empty($product_ids)) {
            $ids = array_map('intval', $product_ids);
            if (in_array($product_id, $ids, true) || ($parent_id && in_array($parent_id, $ids, true))) {
                return true;
            }
        }
        
        if (!empty($category_ids)) {
            $cats = array_map('intval', $category_ids);
            $product_cats = wc_get_product_term_ids($product_id, 'product_cat');
            if (!empty(array_intersect($product_cats, $cats))) {
                return true;
            }
        }
        
        return false;
    }
}
