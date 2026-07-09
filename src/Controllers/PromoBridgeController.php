<?php

namespace Drw\App\Controllers;

use Drw\App\Models\PromoModel;
use Drw\App\Models\PromoTypeRegistry;

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
