<?php

namespace Drw\App\CLI;

if (!defined('ABSPATH')) {
    exit;
}

// This whole class only makes sense (and only compiles — WP_CLI_Command only
// exists when WP-CLI is loaded) inside a WP-CLI request. Wrapping the class
// declaration itself in this guard is a second, independent safety net on
// top of the guarded registration in discount-rules-woo.php: even if this
// file were ever autoloaded on a normal web request, nothing would be
// defined and nothing would run.
if (defined('WP_CLI') && WP_CLI) {

    /**
     * `wp drw benchmark` — measures how expensive OmniDiscount's cart pricing
     * hook is under a configurable number of rules and cart line items.
     *
     * WHAT THIS MEASURES
     * -------------------
     * It times \Drw\App\Controllers\CartController::recalculate_cart_item_prices(),
     * i.e. exactly the callback OmniDiscount registers on
     * `woocommerce_before_calculate_totals` (see CartController::register_hooks()).
     * It does NOT run WooCommerce's own full totals pipeline
     * ($cart->calculate_totals()) and does NOT fire other plugins' callbacks
     * on that hook — this isolates OmniDiscount's own contribution.
     *
     * HOW IT STAYS SAFE ON A REAL SITE
     * ---------------------------------
     * - Test PRODUCTS are plain in-memory \WC_Product_Simple objects that are
     *   NEVER ->save()'d. They get a synthetic id (900000000+) far outside any
     *   real post ID range, purely so rule-targeting code has something to
     *   compare against. Nothing is ever written to wp_posts/wp_postmeta for
     *   them, so there is nothing to clean up for products.
     * - Test RULES are real rows in {$wpdb->prefix}drw_rules (there is no
     *   filter to inject rules in-memory — RuleModel reads straight from the
     *   DB), tagged with source = 'drw_benchmark'. They are deleted by exact
     *   ID at the end, AND by the source tag as a belt-and-suspenders sweep
     *   (also run as a self-healing pass at the START of every run, in case a
     *   previous invocation crashed before it could clean up).
     * - Cleanup runs in a try/catch/finally-style flow AND from a
     *   register_shutdown_function() safety net, so a fatal error mid-run
     *   still can't leave rows behind.
     * - No pre-existing real rules/products on the site are read, modified,
     *   or deleted — this command is purely additive-then-fully-reverted.
     *
     * IMPORTANT CAVEAT: this file was written without a WordPress/WooCommerce
     * environment or WP-CLI available to actually execute it. It has NOT been
     * run or verified. Run it on a disposable staging copy first and confirm
     * (a) it reports sane numbers and (b) `wp drw benchmark` truly leaves the
     * `{$wpdb->prefix}drw_rules` table exactly as it found it, before trusting
     * its output or running it anywhere near production data.
     */
    class BenchmarkCommand extends \WP_CLI_Command
    {
        /**
         * Synthetic product IDs start here — far above any real WordPress
         * post ID a normal install will ever reach — so benchmark products
         * can never collide with (or be mistaken for) real catalog items.
         */
        const PRODUCT_ID_BASE = 900000000;

        /**
         * Tag stored in drw_rules.source for every row this command creates,
         * so cleanup can find (and a crashed prior run's leftovers can be
         * self-healed) without relying on remembering exact IDs.
         */
        const SOURCE_TAG = 'drw_benchmark';

        /**
         * Benchmarks OmniDiscount's cart pricing hook under N temporary rules
         * and M temporary cart line items, then deletes everything it created.
         *
         * ## OPTIONS
         *
         * [--rules=<number>]
         * : Number of temporary discount rules to create for the benchmark.
         * They are real rows in the drw_rules table (tagged and deleted at the
         * end), spread across a realistic mix of adjustment/condition types
         * (percentage, fixed, bulk tiers, specific_products, specific_categories).
         * ---
         * default: 50
         * ---
         *
         * [--products=<number>]
         * : Number of temporary test products used to build the benchmark cart.
         * These are in-memory WC_Product_Simple objects only — never saved to
         * the database, so there is nothing to clean up for them.
         * ---
         * default: 500
         * ---
         *
         * [--cart-items=<number>]
         * : Number of line items placed in the simulated cart. Defaults to
         * --products (i.e. every test product gets one cart line), capped to
         * --products if set higher.
         *
         * [--iterations=<number>]
         * : Number of timed calls used to compute p50/p95. OmniDiscount's
         * internal per-request cache (RulesEngine::clear_cache()) is cleared
         * before every single iteration, so every sample reflects a cold,
         * single-request cost rather than an in-process cache hit — without
         * that, every call after the first would be measuring a near-free
         * cache lookup, not real rule evaluation.
         * ---
         * default: 200
         * ---
         *
         * [--warmup=<number>]
         * : Untimed calls executed before measurement starts, to warm PHP/OPcache.
         * ---
         * default: 5
         * ---
         *
         * [--format=<format>]
         * : Render output as a table, JSON, CSV or YAML.
         * ---
         * default: table
         * options:
         *   - table
         *   - json
         *   - csv
         *   - yaml
         * ---
         *
         * ## EXAMPLES
         *
         *     wp drw benchmark
         *     wp drw benchmark --rules=100 --products=1000 --iterations=500
         *     wp drw benchmark --rules=20 --products=50 --format=json
         *
         * @when after_wp_load
         */
        public function benchmark($args, $assoc_args)
        {
            if (!class_exists('WooCommerce') || !class_exists('\\WC_Cart') || !function_exists('WC')) {
                \WP_CLI::error('WooCommerce must be active to run this benchmark.');
                return;
            }

            if (!class_exists('\\Drw\\App\\Controllers\\CartController') || !class_exists('\\Drw\\App\\Controllers\\RulesEngine')) {
                \WP_CLI::error('OmniDiscount (discount-rules-woo) classes are not available.');
                return;
            }

            global $wpdb;
            $rules_table = $wpdb->prefix . 'drw_rules';

            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $rules_table)) !== $rules_table) {
                \WP_CLI::error(sprintf('Table %s does not exist. Activate the plugin first (this creates it).', $rules_table));
                return;
            }

            $num_rules      = max(1, (int)\WP_CLI\Utils\get_flag_value($assoc_args, 'rules', 50));
            $num_products   = max(1, (int)\WP_CLI\Utils\get_flag_value($assoc_args, 'products', 500));
            $num_cart_items = (int)\WP_CLI\Utils\get_flag_value($assoc_args, 'cart-items', $num_products);
            $num_cart_items = max(1, min($num_cart_items, $num_products));
            $iterations     = max(1, (int)\WP_CLI\Utils\get_flag_value($assoc_args, 'iterations', 200));
            $warmup         = max(0, (int)\WP_CLI\Utils\get_flag_value($assoc_args, 'warmup', 5));
            $format         = (string)\WP_CLI\Utils\get_flag_value($assoc_args, 'format', 'table');

            // Self-heal: if a previous run crashed before reaching its own
            // cleanup, sweep any leftover benchmark rows before we start.
            $this->purge_leftovers($wpdb, $rules_table);

            $created_rule_ids = [];
            $cleaned_up       = false;

            $cleanup = function () use (&$created_rule_ids, &$cleaned_up, $wpdb, $rules_table) {
                if ($cleaned_up) {
                    return;
                }
                $this->delete_rules_by_id($wpdb, $rules_table, $created_rule_ids);
                // Belt-and-suspenders: also sweep by tag, in case any insert
                // above returned an ID we failed to record for some reason.
                $this->purge_leftovers($wpdb, $rules_table);
                \Drw\App\Controllers\RulesEngine::instance()->clear_cache();
                $created_rule_ids = [];
                $cleaned_up = true;
            };

            // Safety net: if the process dies in a way try/catch can't trap
            // (a genuine fatal error), still attempt cleanup before the PHP
            // process actually exits, so a crash never leaves test rows behind.
            register_shutdown_function(function () use ($cleanup, &$cleaned_up) {
                if (!$cleaned_up) {
                    $cleanup();
                    error_log('[discount-rules-woo] wp drw benchmark: shutdown safety net had to clean up temporary rules after an unexpected error.');
                }
            });

            try {
                \WP_CLI::log(sprintf('Creating %d temporary test rule(s) (tagged source=%s)...', $num_rules, self::SOURCE_TAG));
                $created_rule_ids = $this->create_test_rules($wpdb, $rules_table, $num_rules, $num_products);

                // Real rules exist now — force a fresh load on the next call
                // rather than reusing whatever RulesEngine had cached before.
                \Drw\App\Controllers\RulesEngine::instance()->clear_cache();

                \WP_CLI::log(sprintf('Building an in-memory cart with %d line item(s) out of %d test product(s) (nothing persisted to the database)...', $num_cart_items, $num_products));
                $cart = $this->build_benchmark_cart($num_cart_items);

                $cart_controller = \Drw\App\Controllers\CartController::instance();

                if ($warmup > 0) {
                    \WP_CLI::log(sprintf('Warming up (%d untimed call(s))...', $warmup));
                    for ($i = 0; $i < $warmup; $i++) {
                        \Drw\App\Controllers\RulesEngine::instance()->clear_cache();
                        $cart_controller->recalculate_cart_item_prices($cart);
                    }
                }

                \WP_CLI::log(sprintf('Timing %d call(s) to the simulated woocommerce_before_calculate_totals callback...', $iterations));
                $timings_ms = [];
                $progress = \WP_CLI\Utils\make_progress_bar('Benchmarking', $iterations);

                for ($i = 0; $i < $iterations; $i++) {
                    // Clear the per-request cache before every sample: within
                    // one real HTTP request the hook may fire more than once
                    // and hit this cache cheaply, but each SAMPLE here is
                    // meant to represent an independent request's cold cost.
                    \Drw\App\Controllers\RulesEngine::instance()->clear_cache();

                    $start = microtime(true);
                    $cart_controller->recalculate_cart_item_prices($cart);
                    $elapsed = microtime(true) - $start;

                    $timings_ms[] = $elapsed * 1000.0;
                    $progress->tick();
                }
                $progress->finish();

                sort($timings_ms);
                $count = count($timings_ms);
                $result = [
                    'rules'      => $num_rules,
                    'products'   => $num_products,
                    'cart_items' => $num_cart_items,
                    'iterations' => $iterations,
                    'min_ms'     => round($timings_ms[0], 3),
                    'p50_ms'     => round($this->percentile($timings_ms, 50), 3),
                    'p95_ms'     => round($this->percentile($timings_ms, 95), 3),
                    'max_ms'     => round($timings_ms[$count - 1], 3),
                    'avg_ms'     => round(array_sum($timings_ms) / $count, 3),
                ];

                \WP_CLI::log('');
                \WP_CLI\Utils\format_items($format, [$result], array_keys($result));
            } catch (\Throwable $e) {
                $cleanup();
                \WP_CLI::error('Benchmark failed: ' . $e->getMessage());
                return;
            }

            $created_count = count(array_unique($created_rule_ids));
            $cleanup();
            \WP_CLI::success(sprintf(
                'Removed all %d temporary benchmark rule(s). No test data (rules or products) was left in the database.',
                $created_count
            ));
        }

        /**
         * Delete any leftover benchmark rows tagged with our source marker.
         * Safe to call at any time, including on a site with zero leftovers
         * (in which case it is a no-op query).
         *
         * @param \wpdb  $wpdb
         * @param string $rules_table
         */
        private function purge_leftovers($wpdb, $rules_table)
        {
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$rules_table} WHERE source = %s",
                self::SOURCE_TAG
            ));

            if (!empty($deleted)) {
                \WP_CLI::log(sprintf('Found and removed %d leftover benchmark rule(s) from a previous run.', (int)$deleted));
            }
        }

        /**
         * Delete a specific set of rule rows by ID.
         *
         * @param \wpdb  $wpdb
         * @param string $rules_table
         * @param array  $ids
         */
        private function delete_rules_by_id($wpdb, $rules_table, array $ids)
        {
            $ids = array_values(array_unique(array_map('intval', array_filter($ids))));
            if (empty($ids)) {
                return;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$rules_table} WHERE id IN ({$placeholders})",
                $ids
            ));
        }

        /**
         * Insert $num_rules temporary rows directly into drw_rules (same
         * direct-$wpdb-insert pattern PromoBridgeController::compile_rule()
         * already uses in this codebase), covering a realistic mix of
         * adjustment types and condition/targeting shapes so the benchmark
         * exercises the same code paths a real store's rule set would.
         *
         * Deliberately excludes 'bogo' and 'bundle_set' adjustment types:
         * CartController::recalculate_cart_item_prices() reacts to matched
         * bogo rules by calling WC()->cart->add_to_cart() on the REAL global
         * cart/session — something a benchmark must never trigger.
         * 'free_shipping' is also excluded because it is only ever handled as
         * a cart-level rule outside the method being timed here.
         *
         * @param \wpdb  $wpdb
         * @param string $rules_table
         * @param int    $num_rules
         * @param int    $num_products
         * @return int[] IDs of the rows created, for exact cleanup.
         */
        private function create_test_rules($wpdb, $rules_table, $num_rules, $num_products)
        {
            $json_encode = function_exists('wp_json_encode') ? 'wp_json_encode' : 'json_encode';
            $now = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');

            // A stable sample of synthetic product IDs (matching the scheme
            // build_benchmark_cart() uses) for "specific_products" style rules.
            $sample_size = min(20, $num_products);
            $sample_product_ids = [];
            for ($i = 0; $i < $sample_size; $i++) {
                $sample_product_ids[] = self::PRODUCT_ID_BASE + $i;
            }

            $created_ids = [];

            for ($i = 0; $i < $num_rules; $i++) {
                $variant     = $i % 5;
                $apply_to    = 'all_products';
                $conditions  = [];
                $filters     = [
                    'product_ids'          => [],
                    'category_ids'         => [],
                    'exclude_product_ids'  => [],
                    'exclude_category_ids' => [],
                ];

                switch ($variant) {
                    case 0:
                        // Cheapest, most common real-world shape: flat % off everything.
                        $adjustments = ['type' => 'percentage', 'value' => 10];
                        break;

                    case 1:
                        // Cart-level condition (forces get_subtotal() to be read).
                        $adjustments = ['type' => 'fixed', 'value' => 5];
                        $conditions[] = ['type' => 'cart_subtotal', 'operator' => 'greater_than_or_equal', 'value' => 50];
                        break;

                    case 2:
                        // Tiered bulk pricing, targeted at a specific product subset.
                        $adjustments = [
                            'type'  => 'bulk',
                            'tiers' => [
                                ['min' => 1, 'max' => 2, 'type' => 'percentage', 'value' => 5],
                                ['min' => 3, 'max' => 5, 'type' => 'percentage', 'value' => 10],
                                ['min' => 6, 'max' => '', 'type' => 'percentage', 'value' => 15],
                            ],
                        ];
                        $apply_to = 'specific_products';
                        $filters['product_ids'] = $sample_product_ids;
                        $conditions[] = ['type' => 'cart_items_quantity', 'operator' => 'greater_than_or_equal', 'value' => 1];
                        break;

                    case 3:
                        // specific_categories: synthetic products belong to no real
                        // term, so wc_get_product_term_ids() legitimately returns an
                        // empty result — a real (read-only) DB round trip, but no
                        // taxonomy data is ever created, so there is nothing to clean up.
                        $adjustments = ['type' => 'percentage', 'value' => 8];
                        $apply_to = 'specific_categories';
                        $filters['category_ids'] = [self::PRODUCT_ID_BASE + 1, self::PRODUCT_ID_BASE + 2];
                        break;

                    case 4:
                    default:
                        // "products" condition (distinct from apply_to targeting).
                        $adjustments = ['type' => 'fixed', 'value' => 3];
                        $conditions[] = ['type' => 'products', 'operator' => 'in_list', 'value' => $sample_product_ids];
                        break;
                }

                $db_data = [
                    'enabled'     => 1,
                    'deleted'     => 0,
                    'exclusive'   => 0,
                    'title'       => sprintf('[DRW BENCHMARK] Rule %d', $i + 1),
                    'priority'    => 10 + $i,
                    'apply_to'    => $apply_to,
                    'filters'     => $json_encode($filters),
                    'conditions'  => $json_encode($conditions),
                    'adjustments' => $json_encode($adjustments),
                    'date_from'   => null,
                    'date_to'     => null,
                    'usage_limit' => null,
                    'used_count'  => 0,
                    'source'      => self::SOURCE_TAG,
                    'promo_id'    => null,
                    'created_at'  => $now,
                    'modified_at' => $now,
                ];

                $inserted = $wpdb->insert($rules_table, $db_data);
                if ($inserted === false) {
                    throw new \RuntimeException('Failed to insert a benchmark rule: ' . $wpdb->last_error);
                }
                $created_ids[] = (int)$wpdb->insert_id;
            }

            return $created_ids;
        }

        /**
         * Build a throwaway WC_Cart populated with in-memory-only test
         * products. Nothing here touches wp_posts, wp_postmeta, taxonomy
         * tables, or WooCommerce's session/persistent-cart storage:
         *
         * - `new \WC_Cart()` (not WC()->cart) creates a standalone instance.
         *   Its internal WC_Cart_Session hooks itself to 'wp_loaded' to hydrate
         *   from the real session — but 'wp_loaded' has already fired long
         *   before a WP-CLI `@when after_wp_load` command runs, so that never
         *   triggers for this instance.
         * - Cart contents are assigned directly to the cart's `cart_contents`
         *   property (public on WC_Cart — CartController itself relies on this
         *   in recalculate_cart_item_prices()), instead of calling
         *   add_to_cart(), so no 'woocommerce_add_to_cart' hooks fire and the
         *   real customer/session persistent cart is never touched.
         * - Each WC_Product_Simple is constructed with no ID (so its
         *   constructor never attempts to read a post from the database),
         *   then given a synthetic id via set_id() purely so rule-targeting
         *   comparisons have something to compare against. It is never saved.
         *
         * @param int $num_cart_items
         * @return \WC_Cart
         */
        private function build_benchmark_cart($num_cart_items)
        {
            $cart = new \WC_Cart();
            $cart_contents = [];
            $subtotal = 0.0;

            for ($i = 0; $i < $num_cart_items; $i++) {
                $product_id = self::PRODUCT_ID_BASE + $i;
                // Deterministic but varied price/quantity spread.
                $price = round(5 + (($i % 97) * 1.37), 2);
                $qty   = 1 + ($i % 5);

                $product = new \WC_Product_Simple();
                $product->set_id($product_id);
                $product->set_name('DRW Benchmark Product ' . ($i + 1));
                $product->set_regular_price((string)$price);
                $product->set_price((string)$price);
                $product->set_status('publish');
                $product->set_manage_stock(false);
                $product->set_stock_status('instock');

                $key = 'drw_bench_' . $i;
                $line_total = $price * $qty;

                $cart_contents[$key] = [
                    'key'               => $key,
                    'product_id'        => $product_id,
                    'variation_id'      => 0,
                    'variation'         => [],
                    'quantity'          => $qty,
                    'data'              => $product,
                    'data_hash'         => '',
                    'line_tax_data'     => ['subtotal' => [], 'total' => []],
                    'line_subtotal'     => $line_total,
                    'line_subtotal_tax' => 0,
                    'line_total'        => $line_total,
                    'line_tax'          => 0,
                ];

                $subtotal += $line_total;
            }

            // Public property assignment — see CartController::recalculate_cart_item_prices()
            // for the exact same pattern used against a real WC_Cart elsewhere in this plugin.
            $cart->cart_contents = $cart_contents;

            if (method_exists($cart, 'set_subtotal')) {
                $cart->set_subtotal($subtotal);
            } else {
                $cart->subtotal = $subtotal;
            }

            return $cart;
        }

        /**
         * Linear-interpolation percentile over an already-sorted (ascending)
         * array of millisecond timings.
         *
         * @param float[] $sorted
         * @param float   $p 0-100
         * @return float
         */
        private function percentile(array $sorted, $p)
        {
            $count = count($sorted);
            if ($count === 0) {
                return 0.0;
            }
            if ($count === 1) {
                return $sorted[0];
            }

            $index = ($p / 100) * ($count - 1);
            $lower = (int)floor($index);
            $upper = (int)ceil($index);

            if ($lower === $upper) {
                return $sorted[$lower];
            }

            $fraction = $index - $lower;
            return $sorted[$lower] + ($sorted[$upper] - $sorted[$lower]) * $fraction;
        }
    }
}
