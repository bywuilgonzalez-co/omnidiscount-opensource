<?php

namespace Drw\App\Controllers;

use Drw\App\Models\PromoModel;
use Drw\App\Models\PromoTypeRegistry;
use Drw\App\Models\RuleModel;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bridge between the promo catalogue (wp_drw_promos) and the discount engine.
 *
 * PromosController persists promos, but the pricing engine (RulesEngine +
 * CartController + Adjustments/Conditions) only ever reads:
 *   - native WooCommerce coupons, and
 *   - rows in wp_drw_rules (via RuleModel::get_active_rules()).
 *
 * A stored promo therefore produces NO real discount until it is "compiled"
 * into one of those two worlds. This controller performs that compilation
 * WITHOUT touching any calculation code:
 *
 *   Vía A (PromoTypeRegistry::needs_code() === true): syncs a native WC_Coupon.
 *   Vía B (needs_code() === false): compiles a wp_drw_rules row with
 *          source='promo' and promo_id=<id> so it is picked up automatically by
 *          RuleModel::get_active_rules() (which does not filter by source).
 *
 * The build_* methods are pure (no DB / no WooCommerce) so they can be unit
 * tested for shape compatibility against RuleModel::sanitize_* directly.
 *
 * KNOWN LIMITATIONS (documented, not worked around):
 *   - PromosController currently stores scope only as { raw: "<free text>" }
 *     and never emits structured { target, product_ids, category_ids }. Until
 *     the scope editor is upgraded, product/category targeting resolves to
 *     "all products" and bundle_items / bogo buy-lists stay empty. The mapping
 *     already reads the richer shape when present, so no rework is needed later.
 *   - gift_config is stored as { text: "<copy>" }; there is no product id to
 *     hand to the BOGO "get" list, so a 'gift' promo compiles to a BOGO whose
 *     get_products is empty until gift_config carries product ids.
 *   - There is no cashback/points adjustment in the engine. 'cashback' is
 *     approximated as a straight 'percentage' discount (see build_rule_payload).
 */
class PromoBridgeController
{
    // ------------------------------------------------------------------
    // Sandbox mode (admin-only promo preview)
    // ------------------------------------------------------------------
    //
    // WHERE THIS LIVES AND WHY: the cookie is issued by
    // PromosController::activate_sandbox() (POST /drw/v1/promos/<id>/sandbox).
    // Reading/validating it, and resolving it to an engine-visible rule, is
    // implemented HERE (PromoBridgeController) rather than in CartController
    // because this class already owns the ONLY translation from a stored
    // promo row to something the discount engine understands
    // (compile()/build_rule_payload() above). Keeping the cookie parsing next
    // to that translation means CartController only ever calls one small,
    // self-contained, testable method and never touches raw cookie/crypto
    // code itself.
    //
    // CartController calls get_sandboxed_rule_for_current_user() at the top
    // of its pricing hooks and, ONLY when it returns non-null, applies that
    // one extra rule as an additional layer on top of the normal engine
    // output for the current cart. It intentionally does NOT feed the result
    // back into RulesEngine's own active-rules cache (that cache is private
    // with no public mutator) — see CartController for the full note on why
    // that boundary was kept intact.

    /** Name of the cookie carrying the signed promo sandbox token. */
    const SANDBOX_COOKIE_NAME = 'drw_promo_sandbox';

    /**
     * Name of the cookie carrying the signed MANUAL RULE sandbox token
     * (ApiController::activate_rule_sandbox(), POST /drw/v1/rules/<id>/sandbox).
     * Deliberately a SEPARATE cookie/id-space from SANDBOX_COOKIE_NAME: promo
     * ids (wp_drw_promos.id) and rule ids (wp_drw_rules.id) are independent
     * auto-increment sequences, so a single shared cookie could resolve the
     * same numeric id to the wrong entity. Keeping them in separate cookies
     * removes that ambiguity entirely instead of trying to disambiguate a
     * shared payload.
     */
    const SANDBOX_RULE_COOKIE_NAME = 'drw_rule_sandbox';

    /** Sandbox override lifetime in seconds (30 minutes), enforced server-side. */
    const SANDBOX_TTL = 1800;

    /**
     * Build the signed sandbox cookie payload.
     *
     * Format: "{promo_id}:{user_id}:{expires_at}:{signature}" where
     * signature = wp_hash("{promo_id}:{user_id}:{expires_at}"). wp_hash()
     * derives the HMAC key from the site's own AUTH_KEY/AUTH_SALT, so the
     * token cannot be forged or replayed for a different promo/user/expiry
     * without knowing the site's secret keys.
     *
     * @param int $promo_id  Promo primary key.
     * @param int $user_id   WP user id the override is scoped to.
     * @param int $expires_at Unix timestamp the override stops being valid.
     * @return string
     */
    public static function build_sandbox_cookie_value($promo_id, $user_id, $expires_at)
    {
        $payload = self::sandbox_payload($promo_id, $user_id, $expires_at);
        return $payload . ':' . wp_hash($payload);
    }

    /**
     * Resolve the sandboxed rule (if any) for the CURRENT request/user.
     *
     * Tries the two independent sandbox mechanisms, in order, and returns
     * the first one that resolves:
     *   1. Promo sandbox (resolve_promo_sandbox()) — a promo compiled into a
     *      wp_drw_rules row via compile_rule(), issued by
     *      PromosController::activate_sandbox().
     *   2. Manual rule sandbox (resolve_manual_rule_sandbox()) — a
     *      wp_drw_rules row edited directly in the Rules screen, issued by
     *      ApiController::activate_rule_sandbox().
     *
     * Both return a formatted wp_drw_rules row (same shape RuleModel::get_rule()
     * returns) that CartController can pass straight into RulesEngine's
     * existing PUBLIC matching helpers (is_rule_matched(),
     * is_product_targeted_by_rule(), is_cart_level_rule()), or null when
     * neither has a valid override to apply. A caller only ever needs to
     * apply at most one sandboxed rule per request, exactly like the
     * pre-existing promo-only contract.
     *
     * @return array|null
     */
    public static function get_sandboxed_rule_for_current_user()
    {
        $rule = self::resolve_promo_sandbox();
        if (null !== $rule) {
            return $rule;
        }

        return self::resolve_manual_rule_sandbox();
    }

    /**
     * Resolve a PROMO sandbox override for the current request/user.
     *
     * This is a strict allow-list of checks — ANY failure returns null, i.e.
     * "behave exactly as if sandbox mode did not exist":
     *   1. Cookie present.
     *   2. Current visitor is logged in AND current_user_can('manage_woocommerce')
     *      — re-checked on every call, not just at issuance, so revoking the
     *      capability (or logging out) immediately kills the override.
     *   3. Cookie parses into exactly 4 numeric/hex segments.
     *   4. Signature re-derived from the embedded promo_id/user_id/expires_at
     *      matches the stored one via hash_equals() (timing-safe compare) —
     *      an attacker cannot forge or extend a token without the site's
     *      secret keys.
     *   5. embedded user_id === get_current_user_id() — a stolen/shared
     *      cookie is inert for anyone but the admin who generated it.
     *   6. Server-side expiry (from the SIGNED timestamp, not the cookie's
     *      own Expires attribute, which a client could tamper with) has not
     *      passed.
     *   7. The promo still exists, is NOT already genuinely `active` (if it
     *      was published for real in the meantime, the normal engine already
     *      covers it — returning it again here would double-apply it), and
     *      has a compiled wp_drw_rules row.
     *
     * @return array|null
     */
    private static function resolve_promo_sandbox()
    {
        if (empty($_COOKIE[self::SANDBOX_COOKIE_NAME]) || !is_string($_COOKIE[self::SANDBOX_COOKIE_NAME])) {
            return null;
        }

        if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
            return null;
        }

        if (!function_exists('current_user_can') || !current_user_can('manage_woocommerce')) {
            return null;
        }

        $parts = explode(':', wp_unslash($_COOKIE[self::SANDBOX_COOKIE_NAME]));
        if (count($parts) !== 4) {
            return null;
        }

        list($promo_id, $user_id, $expires_at, $signature) = $parts;

        if (!ctype_digit($promo_id) || !ctype_digit($user_id) || !ctype_digit($expires_at) || '' === $signature) {
            return null;
        }

        $promo_id   = (int) $promo_id;
        $user_id    = (int) $user_id;
        $expires_at = (int) $expires_at;

        if ($promo_id <= 0 || $user_id <= 0) {
            return null;
        }

        // A sandbox cookie only ever belongs to the user who generated it.
        if ($user_id !== get_current_user_id()) {
            return null;
        }

        if (time() > $expires_at) {
            return null;
        }

        $expected = wp_hash(self::sandbox_payload($promo_id, $user_id, $expires_at));
        if (!hash_equals($expected, (string) $signature)) {
            return null;
        }

        $promo = PromoModel::get_promo($promo_id);
        if (null === $promo) {
            return null;
        }

        // Already genuinely published: the real engine already includes it,
        // nothing to force here (also avoids double-applying the discount).
        if (!empty($promo['active'])) {
            return null;
        }

        if (PromoTypeRegistry::needs_code($promo['type'])) {
            return null;
        }

        if (empty($promo['rule_id'])) {
            return null;
        }

        $rule = RuleModel::get_rule((int) $promo['rule_id']);
        if (null === $rule) {
            return null;
        }

        return $rule;
    }

    /**
     * Resolve a MANUAL RULE sandbox override for the current request/user.
     *
     * Mirrors resolve_promo_sandbox()'s allow-list exactly, but reads the
     * separate SANDBOX_RULE_COOKIE_NAME cookie and resolves the id embedded
     * in it DIRECTLY against RuleModel::get_rule() — there is no promo row
     * in between, because a manually-created rule never had one to begin
     * with. Every step that isn't specific to that difference (login +
     * capability re-check, signature verification, owner/expiry checks) is
     * identical to the promo path:
     *   1. Cookie present.
     *   2. Current visitor is logged in AND current_user_can('manage_woocommerce').
     *   3. Cookie parses into exactly 4 numeric/hex segments.
     *   4. Signature matches (hash_equals()), so the id/user/expiry embedded
     *      cannot be forged or extended without the site's secret keys.
     *   5. embedded user_id === get_current_user_id().
     *   6. Server-side expiry (signed timestamp) has not passed.
     *   7. The rule still exists (RuleModel::get_rule() already filters
     *      deleted=0) and is NOT already `enabled` — an enabled rule is
     *      already picked up by the normal engine path, so returning it here
     *      too would double-apply the discount.
     *
     * @return array|null
     */
    private static function resolve_manual_rule_sandbox()
    {
        if (empty($_COOKIE[self::SANDBOX_RULE_COOKIE_NAME]) || !is_string($_COOKIE[self::SANDBOX_RULE_COOKIE_NAME])) {
            return null;
        }

        if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
            return null;
        }

        if (!function_exists('current_user_can') || !current_user_can('manage_woocommerce')) {
            return null;
        }

        $parts = explode(':', wp_unslash($_COOKIE[self::SANDBOX_RULE_COOKIE_NAME]));
        if (count($parts) !== 4) {
            return null;
        }

        list($rule_id, $user_id, $expires_at, $signature) = $parts;

        if (!ctype_digit($rule_id) || !ctype_digit($user_id) || !ctype_digit($expires_at) || '' === $signature) {
            return null;
        }

        $rule_id    = (int) $rule_id;
        $user_id    = (int) $user_id;
        $expires_at = (int) $expires_at;

        if ($rule_id <= 0 || $user_id <= 0) {
            return null;
        }

        // A sandbox cookie only ever belongs to the user who generated it.
        if ($user_id !== get_current_user_id()) {
            return null;
        }

        if (time() > $expires_at) {
            return null;
        }

        $expected = wp_hash(self::sandbox_payload($rule_id, $user_id, $expires_at));
        if (!hash_equals($expected, (string) $signature)) {
            return null;
        }

        $rule = RuleModel::get_rule($rule_id);
        if (null === $rule) {
            return null;
        }

        // Already genuinely enabled: the real engine already includes it,
        // nothing to force here (also avoids double-applying the discount).
        if (!empty($rule['enabled'])) {
            return null;
        }

        return $rule;
    }

    /**
     * Build the unsigned "{promo_id}:{user_id}:{expires_at}" payload shared
     * by build_sandbox_cookie_value() (issuance) and
     * get_sandboxed_rule_for_current_user() (verification), so the exact
     * string being signed can never drift between the two.
     *
     * @param int $promo_id
     * @param int $user_id
     * @param int $expires_at
     * @return string
     */
    private static function sandbox_payload($promo_id, $user_id, $expires_at)
    {
        return (int) $promo_id . ':' . (int) $user_id . ':' . (int) $expires_at;
    }

    /**
     * Compile a stored promo into a real, engine-visible discount.
     *
     * @param int $promo_id Promo primary key.
     * @return array|false Result descriptor, or false if the promo is missing.
     */
    public function compile($promo_id)
    {
        $promo = PromoModel::get_promo((int)$promo_id);
        if (null === $promo) {
            return false;
        }

        if (PromoTypeRegistry::needs_code($promo['type'])) {
            return $this->compile_coupon($promo);
        }

        return $this->compile_rule($promo);
    }

    /**
     * Revert compile(): remove the WC_Coupon (Vía A) or soft-delete the
     * wp_drw_rules row (Vía B). Used when a promo is paused or deleted.
     *
     * @param int $promo_id Promo primary key.
     * @return array|false Result descriptor, or false if the promo is missing.
     */
    public function decompile($promo_id)
    {
        $promo = PromoModel::get_promo((int)$promo_id);
        if (null === $promo) {
            return false;
        }

        if (PromoTypeRegistry::needs_code($promo['type'])) {
            return $this->decompile_coupon($promo);
        }

        return $this->decompile_rule($promo);
    }

    // ------------------------------------------------------------------
    // Vía A – native WooCommerce coupon
    // ------------------------------------------------------------------

    /**
     * Create/update the native WC_Coupon mirroring a code-based promo.
     *
     * @param array $promo Formatted promo row.
     * @return array
     */
    private function compile_coupon($promo)
    {
        $promo_id = (int)$promo['id'];
        $code     = (string)$promo['code'];
        $data     = $this->build_coupon_data($promo);

        // Idempotency: reuse the coupon we already own for this code/promo.
        $coupon = null;
        $existing_id = function_exists('wc_get_coupon_id_by_code') ? (int)wc_get_coupon_id_by_code($code) : 0;
        if ($existing_id > 0) {
            $candidate = new \WC_Coupon($existing_id);
            if ((string)$candidate->get_meta('_drw_promo_id') === (string)$promo_id) {
                $coupon = $candidate;
            }
        }
        if (null === $coupon) {
            $coupon = new \WC_Coupon();
        }

        $coupon->set_code($code);
        $coupon->set_discount_type($data['discount_type']);
        $coupon->set_amount($data['amount']);
        $coupon->set_free_shipping((bool)$data['free_shipping']);

        if (null !== $data['date_expires']) {
            $coupon->set_date_expires($data['date_expires']);
        }
        if (null !== $data['usage_limit']) {
            $coupon->set_usage_limit($data['usage_limit']);
        }
        if (null !== $data['usage_limit_per_user']) {
            $coupon->set_usage_limit_per_user($data['usage_limit_per_user']);
        }
        if (null !== $data['minimum_amount']) {
            $coupon->set_minimum_amount($data['minimum_amount']);
        }
        if (!empty($data['product_ids'])) {
            $coupon->set_product_ids($data['product_ids']);
        }
        if (!empty($data['product_categories'])) {
            $coupon->set_product_categories($data['product_categories']);
        }

        $coupon->update_meta_data('_drw_promo_id', $promo_id);
        $coupon->save();

        $coupon_id = (int)$coupon->get_id();
        PromoModel::update($promo_id, ['wc_coupon_id' => $coupon_id]);

        return [
            'via'          => 'A',
            'wc_coupon_id' => $coupon_id,
        ];
    }

    /**
     * Delete the WC_Coupon owned by a promo and clear its stored pointer.
     *
     * @param array $promo Formatted promo row.
     * @return array
     */
    private function decompile_coupon($promo)
    {
        $promo_id    = (int)$promo['id'];
        $code        = (string)$promo['code'];
        $coupon_id   = !empty($promo['wc_coupon_id']) ? (int)$promo['wc_coupon_id'] : 0;

        if ($coupon_id <= 0 && function_exists('wc_get_coupon_id_by_code')) {
            $coupon_id = (int)wc_get_coupon_id_by_code($code);
        }

        $deleted = false;
        if ($coupon_id > 0) {
            $coupon = new \WC_Coupon($coupon_id);
            // Only remove coupons we own.
            if ((string)$coupon->get_meta('_drw_promo_id') === (string)$promo_id) {
                $coupon->delete(true);
                $deleted = true;
            }
        }

        PromoModel::update($promo_id, ['wc_coupon_id' => null]);

        return [
            'via'     => 'A',
            'deleted' => $deleted,
        ];
    }

    /**
     * Map a promo onto native coupon fields (pure, no WooCommerce calls).
     *
     * discount_type: 'fixed_cart' for type=fixed (and the flat free_ship
     * carrier); 'percent' otherwise (percent/welcome/data_capture and, when
     * routed here, second_unit/cashback). free_shipping is toggled for
     * type=free_ship.
     *
     * @param array $promo Formatted promo row.
     * @return array
     */
    public function build_coupon_data($promo)
    {
        $type  = (string)$promo['type'];
        $value = (float)$promo['value'];

        $discount_type = ('fixed' === $type || 'free_ship' === $type) ? 'fixed_cart' : 'percent';

        list($target, $product_ids, $category_ids) = $this->derive_target($promo);

        $limit_global = !empty($promo['limit_global']) ? (int)$promo['limit_global'] : 0;
        $limit_user   = !empty($promo['limit_user']) ? (int)$promo['limit_user'] : 0;
        $min_amount   = isset($promo['min_amount']) ? (float)$promo['min_amount'] : 0.0;

        return [
            'discount_type'        => $discount_type,
            'amount'               => $value,
            'free_shipping'        => ('free_ship' === $type),
            'date_expires'         => $this->to_timestamp(isset($promo['date_to']) ? $promo['date_to'] : null),
            'usage_limit'          => $limit_global > 0 ? $limit_global : null,
            'usage_limit_per_user' => $limit_user > 0 ? $limit_user : null,
            'minimum_amount'       => $min_amount > 0 ? $min_amount : null,
            'product_ids'          => ('products' === $target) ? $product_ids : [],
            'product_categories'   => ('categories' === $target) ? $category_ids : [],
            'meta'                 => ['_drw_promo_id' => (int)$promo['id']],
        ];
    }

    // ------------------------------------------------------------------
    // Vía B – wp_drw_rules row
    // ------------------------------------------------------------------

    /**
     * Insert/update the wp_drw_rules row that realises an automatic promo.
     *
     * @param array $promo Formatted promo row.
     * @return array
     */
    private function compile_rule($promo)
    {
        global $wpdb;

        $promo_id = (int)$promo['id'];
        $payload  = $this->build_rule_payload($promo);
        $table    = $wpdb->prefix . 'drw_rules';
        $json     = function_exists('wp_json_encode') ? 'wp_json_encode' : 'json_encode';

        $db_data = [
            'enabled'     => !empty($promo['active']) ? 1 : 0,
            'deleted'     => 0,
            'exclusive'   => 0,
            'title'       => $payload['title'],
            'priority'    => 10,
            'apply_to'    => $payload['apply_to'],
            'filters'     => $json($payload['filters']),
            'conditions'  => $json($payload['conditions']),
            'adjustments' => $json($payload['adjustments']),
            'date_from'   => $payload['date_from'],
            'date_to'     => $payload['date_to'],
            'usage_limit' => !empty($promo['limit_global']) ? (int)$promo['limit_global'] : null,
            'source'      => 'promo',
            'promo_id'    => $promo_id,
            'modified_at' => current_time('mysql'),
        ];

        // Idempotency: one rule per promo.
        $rule_id = (int)$wpdb->get_var(
            $wpdb->prepare("SELECT id FROM $table WHERE promo_id = %d AND source = 'promo' LIMIT 1", $promo_id)
        );

        if ($rule_id > 0) {
            $wpdb->update($table, $db_data, ['id' => $rule_id]);
        } else {
            $db_data['created_at'] = current_time('mysql');
            $db_data['used_count'] = 0;
            $wpdb->insert($table, $db_data);
            $rule_id = (int)$wpdb->insert_id;
        }

        PromoModel::update($promo_id, ['rule_id' => $rule_id]);

        return [
            'via'     => 'B',
            'rule_id' => $rule_id,
        ];
    }

    /**
     * Soft-delete the wp_drw_rules row owned by a promo.
     *
     * @param array $promo Formatted promo row.
     * @return array
     */
    private function decompile_rule($promo)
    {
        global $wpdb;

        $promo_id = (int)$promo['id'];
        $table    = $wpdb->prefix . 'drw_rules';

        $updated = $wpdb->update(
            $table,
            ['deleted' => 1, 'modified_at' => current_time('mysql')],
            ['promo_id' => $promo_id, 'source' => 'promo']
        );

        return [
            'via'      => 'B',
            'disabled' => (int)$updated,
        ];
    }

    /**
     * Map a promo onto a rule payload whose adjustments/conditions match the
     * exact shapes RuleModel::sanitize_adjustments()/sanitize_conditions()
     * accept. Pure (no DB / no WooCommerce): the returned adjustments array
     * always carries an allowed `type` so sanitisation never rewrites it.
     *
     * @param array $promo Formatted promo row.
     * @return array {
     *   @type string $title
     *   @type string $apply_to
     *   @type array  $filters
     *   @type array  $conditions
     *   @type array  $adjustments
     *   @type int|null $date_from
     *   @type int|null $date_to
     * }
     */
    public function build_rule_payload($promo)
    {
        $type  = (string)$promo['type'];
        $value = (float)$promo['value'];

        list($target, $product_ids, $category_ids) = $this->derive_target($promo);

        // Default targeting derived from scope (used by percentage/fixed/bulk).
        $apply_to = 'all_products';
        $filters  = [
            'product_ids'          => [],
            'category_ids'         => [],
            'exclude_product_ids'  => [],
            'exclude_category_ids' => [],
        ];
        if ('products' === $target && !empty($product_ids)) {
            $apply_to = 'specific_products';
            $filters['product_ids'] = $product_ids;
        } elseif ('categories' === $target && !empty($category_ids)) {
            $apply_to = 'specific_categories';
            $filters['category_ids'] = $category_ids;
        }

        $conditions  = [];
        $adjustments = [];

        switch ($type) {
            case 'launch': // Launch price: a flat currency price cut over a window.
                $adjustments = ['type' => 'fixed', 'value' => $value];
                break;

            case 'flash': // Flash sale: percentage off over a window.
                $adjustments = ['type' => 'percentage', 'value' => $value];
                break;

            case 'cashback':
                // Known limitation: no cashback/points adjustment exists in the
                // engine. Approximated as a straight percentage discount.
                $adjustments = ['type' => 'percentage', 'value' => $value];
                break;

            case '2x1': // Buy 1, get 1 of the same product free.
                $adjustments = $this->bogo_same(1, 1, 'free', 0.0, $product_ids, $category_ids);
                break;

            case '3x2': // Buy 2, get 1 of the same product free.
                $adjustments = $this->bogo_same(2, 1, 'free', 0.0, $product_ids, $category_ids);
                break;

            case 'second_unit': // Buy 1, second identical unit at $value% off.
                $adjustments = $this->bogo_same(1, 1, 'percent', $value, $product_ids, $category_ids);
                break;

            case 'gift': // Buy from scope, get a (different) gift product free.
                $get_products = $this->gift_products($promo);
                $adjustments  = [
                    'type'             => 'bogo',
                    'get_product_type' => 'different',
                    'buy_qty'          => 1,
                    'get_qty'          => 1,
                    'discount_type'    => 'free',
                    'discount_value'   => 0.0,
                    'buy_products'     => $product_ids,
                    'buy_categories'   => $category_ids,
                    'get_products'     => $get_products,
                    'get_categories'   => [],
                ];
                // BOGO applies to the whole cart via buy/get lists, not apply_to.
                $apply_to = 'all_products';
                $filters['product_ids'] = [];
                $filters['category_ids'] = [];
                break;

            case 'bundle': // Fixed set price for a group of items.
                $adjustments = [
                    'type'         => 'bundle_set',
                    'bundle_price' => $value,
                    'bundle_items' => $this->bundle_items($promo, $product_ids),
                ];
                $apply_to = 'all_products';
                $filters['product_ids'] = [];
                $filters['category_ids'] = [];
                break;

            case 'tiered': // Bulk tiers by quantity from tier_config.
                $adjustments = [
                    'type'  => 'bulk',
                    'tiers' => $this->bulk_tiers($promo),
                ];
                break;

            case 'free_ship_threshold':
                // Free shipping once the cart subtotal reaches min_amount.
                $threshold   = isset($promo['min_amount']) ? (float)$promo['min_amount'] : 0.0;
                $adjustments = [
                    'type'         => 'free_shipping',
                    'min_subtotal' => $threshold,
                    'apply_to'     => 'all',
                ];
                // Gate through a real CartSubtotal condition as well.
                $conditions = [[
                    'type'     => 'cart_subtotal',
                    'operator' => 'greater_than_or_equal',
                    'value'    => $threshold,
                ]];
                // free_shipping is only a cart-level rule when apply_to=all_products.
                $apply_to = 'all_products';
                $filters['product_ids'] = [];
                $filters['category_ids'] = [];
                break;

            default:
                // Any code-based type reaching here (shouldn't) degrades to a
                // percentage so the engine still receives a valid adjustment.
                $adjustments = ['type' => 'percentage', 'value' => $value];
                break;
        }

        return [
            'title'       => isset($promo['name']) ? (string)$promo['name'] : '',
            'apply_to'    => $apply_to,
            'filters'     => $filters,
            'conditions'  => $conditions,
            'adjustments' => $adjustments,
            'date_from'   => $this->to_timestamp(isset($promo['date_from']) ? $promo['date_from'] : null),
            'date_to'     => $this->to_timestamp(isset($promo['date_to']) ? $promo['date_to'] : null),
        ];
    }

    // ------------------------------------------------------------------
    // Mapping helpers
    // ------------------------------------------------------------------

    /**
     * Build a "same product" BOGO adjustment array.
     *
     * @param int    $buy_qty
     * @param int    $get_qty
     * @param string $discount_type One of the Bogo engine discount types.
     * @param float  $discount_value
     * @param int[]  $product_ids
     * @param int[]  $category_ids
     * @return array
     */
    private function bogo_same($buy_qty, $get_qty, $discount_type, $discount_value, $product_ids, $category_ids)
    {
        return [
            'type'             => 'bogo',
            'get_product_type' => 'same',
            'buy_qty'          => (int)$buy_qty,
            'get_qty'          => (int)$get_qty,
            'discount_type'    => $discount_type,
            'discount_value'   => (float)$discount_value,
            'buy_products'     => $product_ids,
            'buy_categories'   => $category_ids,
            'get_products'     => [],
            'get_categories'   => [],
        ];
    }

    /**
     * Resolve scope targeting. Reads the richer { target, product_ids,
     * category_ids } shape when present; tolerates the current { raw: ... }
     * envelope (falls back to target='all').
     *
     * @param array $promo Formatted promo row.
     * @return array{0:string,1:int[],2:int[]} [target, product_ids, category_ids]
     */
    private function derive_target($promo)
    {
        $scope = (isset($promo['scope']) && is_array($promo['scope'])) ? $promo['scope'] : [];

        $target = isset($scope['target']) ? (string)$scope['target'] : 'all';
        $product_ids = (isset($scope['product_ids']) && is_array($scope['product_ids']))
            ? $this->int_list($scope['product_ids']) : [];
        $category_ids = (isset($scope['category_ids']) && is_array($scope['category_ids']))
            ? $this->int_list($scope['category_ids']) : [];

        return [$target, $product_ids, $category_ids];
    }

    /**
     * Extract the free gift product ids from gift_config, if any were stored.
     * Current gift_config is { text: ... } only, so this is usually empty
     * (documented limitation).
     *
     * @param array $promo Formatted promo row.
     * @return int[]
     */
    private function gift_products($promo)
    {
        $gift = (isset($promo['gift_config']) && is_array($promo['gift_config'])) ? $promo['gift_config'] : [];

        foreach (['get_products', 'product_ids', 'products'] as $key) {
            if (isset($gift[$key]) && is_array($gift[$key])) {
                return $this->int_list($gift[$key]);
            }
        }
        if (isset($gift['product_id']) && (int)$gift['product_id'] > 0) {
            return [(int)$gift['product_id']];
        }

        return [];
    }

    /**
     * Build bundle_items in the { id, qty } shape BundleSet + RuleModel expect.
     * Prefers an explicit scope.bundle_items list; otherwise derives one item
     * per scope product id (qty 1).
     *
     * @param array $promo       Formatted promo row.
     * @param int[] $product_ids Scope product ids.
     * @return array
     */
    private function bundle_items($promo, $product_ids)
    {
        $scope = (isset($promo['scope']) && is_array($promo['scope'])) ? $promo['scope'] : [];

        if (isset($scope['bundle_items']) && is_array($scope['bundle_items'])) {
            $items = [];
            foreach ($scope['bundle_items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id  = isset($item['id']) ? (int)$item['id'] : (isset($item['product_id']) ? (int)$item['product_id'] : 0);
                $qty = isset($item['qty']) ? max(1, (int)$item['qty']) : 1;
                if ($id > 0) {
                    $items[] = ['id' => $id, 'qty' => $qty];
                }
            }
            return $items;
        }

        $items = [];
        foreach ($product_ids as $id) {
            $items[] = ['id' => (int)$id, 'qty' => 1];
        }
        return $items;
    }

    /**
     * Build bulk tiers from tier_config. Tolerates {min,max,type,value} plus a
     * couple of common aliases. Current PromosController never populates
     * tier_config, so this is usually empty (documented limitation).
     *
     * @param array $promo Formatted promo row.
     * @return array
     */
    private function bulk_tiers($promo)
    {
        $config = (isset($promo['tier_config']) && is_array($promo['tier_config'])) ? $promo['tier_config'] : [];

        // Allow either a bare list or { tiers: [...] }.
        if (isset($config['tiers']) && is_array($config['tiers'])) {
            $config = $config['tiers'];
        }

        $tiers = [];
        foreach ($config as $tier) {
            if (!is_array($tier)) {
                continue;
            }
            $min  = isset($tier['min']) ? (int)$tier['min'] : (isset($tier['from']) ? (int)$tier['from'] : 0);
            $max  = isset($tier['max']) ? $tier['max'] : (isset($tier['to']) ? $tier['to'] : '');
            $ttyp = !empty($tier['type']) ? (string)$tier['type'] : 'percentage';
            $tval = isset($tier['value']) ? (float)$tier['value'] : (isset($tier['discount']) ? (float)$tier['discount'] : 0.0);

            $tiers[] = [
                'min'   => $min,
                'max'   => ('' === $max || null === $max) ? '' : (int)$max,
                'type'  => ('fixed' === $ttyp) ? 'fixed' : 'percentage',
                'value' => $tval,
            ];
        }
        return $tiers;
    }

    /**
     * Normalize a mixed list into unique positive integers.
     *
     * @param mixed $ids
     * @return int[]
     */
    private function int_list($ids)
    {
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        $out = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $out[] = $id;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Convert a DATETIME / date string into a UNIX timestamp, or null.
     *
     * @param mixed $value
     * @return int|null
     */
    private function to_timestamp($value)
    {
        if (empty($value)) {
            return null;
        }
        if (is_numeric($value)) {
            return (int)$value;
        }
        $ts = strtotime((string)$value);
        return $ts !== false ? $ts : null;
    }
}
