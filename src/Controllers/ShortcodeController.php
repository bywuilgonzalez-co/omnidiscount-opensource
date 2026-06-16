<?php

namespace Drw\App\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

class ShortcodeController
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
     * Register public shortcodes and assets.
     */
    public function register_hooks()
    {
        if (function_exists('add_shortcode')) {
            add_shortcode('drw_sale_items_list', [$this, 'render_sale_items_list']);
            add_shortcode('awdr_sale_items_list', [$this, 'render_sale_items_list']);
            add_shortcode('on_sale', [$this, 'render_sale_items_list']);
        }

        if (function_exists('add_action')) {
            add_action('wp_enqueue_scripts', [$this, 'enqueue_public_assets']);
            add_action('wp_ajax_drw_sale_items', [$this, 'ajax_load_sale_items']);
            add_action('wp_ajax_nopriv_drw_sale_items', [$this, 'ajax_load_sale_items']);
        }
    }

    /**
     * Enqueue public CSS used by dynamic sale badges and shortcode cards.
     */
    public function enqueue_public_assets()
    {
        wp_enqueue_style(
            'drw-public-style',
            DRW_PLUGIN_URL . 'assets/css/public-style.css',
            [],
            DRW_VERSION
        );

        wp_enqueue_script(
            'drw-shortcode',
            DRW_PLUGIN_URL . 'assets/js/drw-shortcode.js',
            [],
            DRW_VERSION,
            true
        );
    }

    /**
     * Render sale products.
     *
     * Supported examples:
     * [awdr_sale_items_list]
     * [awdr_sale_items_list category="combos" limit="12" columns="4"]
     */
    public function render_sale_items_list($atts = [])
    {
        $atts = shortcode_atts([
            'limit'      => 0,
            'per_page'   => 12,
            'columns'    => 4,
            'category'   => '',
            'ids'        => '',
            'scan_limit' => 500,
            'class'      => '',
            'orderby'    => 'date',
            'show_sort'  => 'yes',
        ], (array)$atts, 'drw_sale_items_list');

        // `limit` is a legacy alias for `per_page`
        $per_page   = $atts['limit'] > 0 ? min(48, absint($atts['limit'])) : min(48, max(4, absint($atts['per_page'])));
        $columns    = min(6, max(1, absint($atts['columns'])));
        $scan_limit = min(1000, max($per_page, absint($atts['scan_limit'])));
        $category   = sanitize_text_field($atts['category']);
        $ids        = $this->parse_id_list($atts['ids']);
        $orderby    = sanitize_key($atts['orderby']);
        $show_sort  = $atts['show_sort'] !== 'no';

        $this->enqueue_public_assets();

        $all_cards = $this->collect_sale_cards($ids, $category, $scan_limit, $orderby);
        $total     = count($all_cards);

        if ($total === 0) {
            return '<div class="drw-sale-items-empty">' . esc_html__('No sale products found.', 'discount-rules-woo') . '</div>';
        }

        $first_page = array_slice($all_cards, 0, $per_page);
        $has_more   = $total > $per_page;

        $config = wp_json_encode([
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('drw_sale_items'),
            'page'      => 1,
            'perPage'   => $per_page,
            'total'     => $total,
            'hasMore'   => $has_more,
            'category'  => $category,
            'orderby'   => $orderby,
            'scanLimit' => $scan_limit,
            'ids'       => implode(',', $ids),
            'columns'   => $columns,
        ]);

        $class      = sanitize_text_field($atts['class']);
        $sort_html  = $show_sort ? $this->render_sort_bar($orderby, $category, $total, min($per_page, $total)) : '';
        $loader     = '<div class="drw-sale-loading" aria-hidden="true"><span class="drw-sale-spinner"></span><span class="drw-sale-spinner-text">' . esc_html__('Cargando más productos...', 'discount-rules-woo') . '</span></div>';
        $sentinel   = '<div class="drw-sale-sentinel"></div>';

        return sprintf(
            '<div class="drw-sale-wrap %s" data-drw-config="%s">%s<div class="drw-sale-items-grid" style="--drw-sale-columns:%d;">%s</div>%s%s</div>',
            esc_attr($class),
            esc_attr($config),
            $sort_html,
            $columns,
            implode('', $first_page),
            $loader,
            $sentinel
        );
    }

    /**
     * Build a broad candidate list from active discount rules before falling back
     * to a generic product scan. This lets dynamic-rule products appear even when
     * they are not in the first page of products.
     */
    private function get_sale_candidate_product_ids(array $ids, $category, $scan_limit, $orderby = 'date')
    {
        if (!empty($ids)) {
            return $this->query_product_ids([
                'post__in' => $ids,
                'orderby'  => 'post__in',
            ], $category, min($scan_limit, count($ids)), $orderby);
        }

        $candidate_ids = [];
        $engine = RulesEngine::instance();
        $rules = method_exists($engine, 'get_active_rules') ? $engine->get_active_rules() : [];

        foreach ((array)$rules as $rule) {
            $adjustments = !empty($rule['adjustments']) ? (array)$rule['adjustments'] : [];
            $type = !empty($adjustments['type']) ? $adjustments['type'] : '';
            if (!in_array($type, ['percentage', 'fixed', 'bulk'], true)) {
                continue;
            }

            $apply_to = !empty($rule['apply_to']) ? $rule['apply_to'] : 'all_products';
            $filters = !empty($rule['filters']) ? (array)$rule['filters'] : [];

            if ($apply_to === 'specific_products' && !empty($filters['product_ids'])) {
                $candidate_ids = array_merge(
                    $candidate_ids,
                    $this->query_product_ids([
                        'post__in' => array_map('intval', (array)$filters['product_ids']),
                        'orderby'  => 'post__in',
                    ], $category, $scan_limit, $orderby)
                );
            } elseif ($apply_to === 'specific_categories' && !empty($filters['category_ids'])) {
                $candidate_ids = array_merge(
                    $candidate_ids,
                    $this->query_product_ids([
                        'tax_query' => [
                            [
                                'taxonomy' => 'product_cat',
                                'field'    => 'term_id',
                                'terms'    => array_map('intval', (array)$filters['category_ids']),
                            ],
                        ],
                    ], $category, $scan_limit, $orderby)
                );
            } elseif ($apply_to === 'all_products') {
                $candidate_ids = array_merge(
                    $candidate_ids,
                    $this->query_product_ids([], $category, $scan_limit, $orderby)
                );
            }
        }

        if (function_exists('wc_get_product_ids_on_sale')) {
            $candidate_ids = array_merge($candidate_ids, array_map('intval', (array)wc_get_product_ids_on_sale()));
        }

        $candidate_ids = array_values(array_unique(array_filter(array_map('intval', $candidate_ids))));
        if (empty($candidate_ids)) {
            $candidate_ids = $this->query_product_ids([], $category, $scan_limit, $orderby);
        }

        return $candidate_ids;
    }

    /**
     * Query product IDs with optional shortcode category narrowing.
     */
    private function query_product_ids(array $extra_args, $category, $limit, $orderby = 'date')
    {
        $order_args = $this->get_orderby_args($orderby);

        $query_args = array_merge([
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'posts_per_page'         => $limit,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ], $order_args, $extra_args);

        if ($category !== '') {
            $category_filter = [
                [
                    'taxonomy' => 'product_cat',
                    'field'    => 'slug',
                    'terms'    => array_map('trim', explode(',', $category)),
                ],
            ];

            if (!empty($query_args['tax_query'])) {
                $query_args['tax_query'] = array_merge((array)$query_args['tax_query'], $category_filter);
            } else {
                $query_args['tax_query'] = $category_filter;
            }
        }

        return get_posts($query_args);
    }

    /**
     * Build sale data for simple or variable products.
     */
    public static function get_sale_data_for_product($product)
    {
        if (!$product) {
            return null;
        }

        if ($product->is_type('variable')) {
            $best = null;
            foreach ((array)$product->get_visible_children() as $variation_id) {
                $variation = function_exists('wc_get_product') ? wc_get_product($variation_id) : null;
                $sale_data = self::get_simple_sale_data($variation);
                if ($sale_data && (!$best || $sale_data['percentage'] > $best['percentage'])) {
                    $best = $sale_data;
                }
            }
            return $best;
        }

        return self::get_simple_sale_data($product);
    }

    /**
     * Render exact requested percentage badge markup.
     */
    public static function render_sale_percentage_badge($percentage)
    {
        $percentage = (int)$percentage;
        if ($percentage <= 0) {
            return '';
        }

        return '<div class="drw-sale-badge">-' . esc_html($percentage) . ' %</div>';
    }

    /**
     * Calculate sale data for one concrete product or variation.
     */
    private static function get_simple_sale_data($product)
    {
        if (!$product) {
            return null;
        }

        $regular_price = (float)$product->get_regular_price();
        if ($regular_price <= 0) {
            return null;
        }

        $candidate_prices = [];
        $native_sale = (float)$product->get_sale_price();
        if ($native_sale > 0 && $native_sale < $regular_price) {
            $candidate_prices[] = $native_sale;
        }

        $dynamic_sale = RulesEngine::instance()->calculate_catalog_discount($product, $regular_price);
        if ($dynamic_sale !== null && (float)$dynamic_sale > 0 && (float)$dynamic_sale < $regular_price) {
            $candidate_prices[] = (float)$dynamic_sale;
        }

        if (empty($candidate_prices)) {
            return null;
        }

        $sale_price = min($candidate_prices);
        $percentage = (int)round((($regular_price - $sale_price) / $regular_price) * 100);

        if ($percentage <= 0) {
            return null;
        }

        return [
            'percentage'    => $percentage,
            'regular_price' => $regular_price,
            'sale_price'    => $sale_price,
        ];
    }

    /**
     * Render one product card.
     */
    private function render_product_card($product, array $sale_data)
    {
        $is_variation = $product->is_type('variation');

        if ($is_variation) {
            $parent_id    = (int)$product->get_parent_id();
            $product_url  = get_permalink($parent_id);
            $display_name = $product->get_name();
            $cat_post_id  = $parent_id;
            $data_id      = $parent_id;

            // "Imagen del producto" = parent featured image, then parent gallery
            $image = get_the_post_thumbnail($parent_id, 'woocommerce_thumbnail', ['class' => 'drw-sale-item-image']);
            if (empty($image)) {
                $parent  = wc_get_product($parent_id);
                $gallery = $parent ? $parent->get_gallery_image_ids() : [];
                if (!empty($gallery)) {
                    $image = wp_get_attachment_image($gallery[0], 'woocommerce_thumbnail', false, ['class' => 'drw-sale-item-image']);
                }
            }

            $btn_url   = $product_url;
            $btn_class = 'drw-sale-item-btn';
        } else {
            $display_id   = (int)$product->get_id();
            $product_url  = get_permalink($display_id);
            $display_name = $product->get_name();
            $cat_post_id  = $display_id;
            $data_id      = $display_id;

            $image = get_the_post_thumbnail($display_id, 'woocommerce_thumbnail', ['class' => 'drw-sale-item-image']);
            if (empty($image)) {
                $gallery = $product->get_gallery_image_ids();
                if (!empty($gallery)) {
                    $image = wp_get_attachment_image($gallery[0], 'woocommerce_thumbnail', false, ['class' => 'drw-sale-item-image']);
                }
            }

            if ($product->is_type('variable') || $product->is_type('grouped')) {
                $btn_url   = $product_url;
                $btn_class = 'drw-sale-item-btn';
            } else {
                $btn_url   = $product->add_to_cart_url();
                $btn_class = 'drw-sale-item-btn add_to_cart_button ajax_add_to_cart';
            }
        }

        if (empty($image) && function_exists('wc_placeholder_img')) {
            $image = wc_placeholder_img('woocommerce_thumbnail', ['class' => 'drw-sale-item-image']);
        }

        // First product category
        $terms = function_exists('get_the_terms') ? get_the_terms($cat_post_id, 'product_cat') : false;
        $category_html = '';
        if (!empty($terms) && !is_wp_error($terms)) {
            $category_html = '<span class="drw-sale-item-cat">' . esc_html($terms[0]->name) . '</span>';
        }

        $price_html = sprintf(
            '<span class="drw-sale-item-price"><del>%s</del> <ins>%s</ins></span>',
            wc_price($sale_data['regular_price']),
            wc_price($sale_data['sale_price'])
        );

        $button = sprintf(
            '<a href="%s" data-product_id="%d" data-quantity="1" class="%s" aria-label="%s" rel="nofollow">%s</a>',
            esc_url($btn_url),
            $data_id,
            esc_attr($btn_class),
            esc_attr($display_name),
            esc_html__('Agregar', 'discount-rules-woo')
        );

        return sprintf(
            '<article class="drw-sale-item">
            <a class="drw-sale-item-link" href="%s">
                <span class="drw-sale-item-media">%s%s</span>
                <span class="drw-sale-item-body">%s<span class="drw-sale-item-title">%s</span>%s</span>
            </a>
            <div class="drw-sale-item-footer">%s</div>
        </article>',
            esc_url($product_url),
            self::render_sale_percentage_badge($sale_data['percentage']),
            $image,
            $category_html,
            esc_html($display_name),
            $price_html,
            $button
        );
    }

    /**
     * Collect all products with active discounts and return their rendered cards.
     * Applies deduplication (variations take priority over parent) and optional post-sort.
     */
    private function collect_sale_cards(array $ids, $category, $scan_limit, $orderby)
    {
        $product_ids = $this->get_sale_candidate_product_ids($ids, $category, $scan_limit, $orderby);

        // Pre-pass: identify parent IDs covered by individual variations
        $covered_parent_ids = [];
        if (function_exists('wc_get_product')) {
            foreach ($product_ids as $pid) {
                $pid = is_object($pid) && isset($pid->ID) ? (int)$pid->ID : (int)$pid;
                $p   = wc_get_product($pid);
                if ($p && $p->is_type('variation')) {
                    $covered_parent_ids[(int)$p->get_parent_id()] = true;
                }
            }
        }

        $items = [];
        foreach ($product_ids as $product_id) {
            $product_id = is_object($product_id) && isset($product_id->ID) ? $product_id->ID : $product_id;
            $product    = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
            if (!$product) {
                continue;
            }
            if ($product->is_type('variable') && isset($covered_parent_ids[(int)$product->get_id()])) {
                continue;
            }

            $sale_data = self::get_sale_data_for_product($product);
            if (empty($sale_data['percentage'])) {
                continue;
            }

            $items[] = ['product' => $product, 'sale_data' => $sale_data];
        }

        // Sort by discount percentage after filtering (requires full data set)
        if ($orderby === 'discount') {
            usort($items, function ($a, $b) {
                return $b['sale_data']['percentage'] - $a['sale_data']['percentage'];
            });
        }

        return array_map(function ($item) {
            return $this->render_product_card($item['product'], $item['sale_data']);
        }, $items);
    }

    /**
     * Render the sort/filter toolbar (count text + category filter + orderby dropdown).
     */
    private function render_sort_bar($orderby, $category, $total, $shown)
    {
        $count_text = sprintf(
            __('Mostrando 1&ndash;%1$d de %2$d resultados', 'discount-rules-woo'),
            $shown,
            $total
        );

        // Category filter
        $cat_terms = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        $cat_opts = '<option value="">' . esc_html__('Todas las categorías', 'discount-rules-woo') . '</option>';
        if (!is_wp_error($cat_terms) && !empty($cat_terms)) {
            foreach ($cat_terms as $term) {
                $cat_opts .= sprintf(
                    '<option value="%s"%s>%s</option>',
                    esc_attr($term->slug),
                    selected($term->slug, $category, false),
                    esc_html($term->name)
                );
            }
        }

        // Sort options
        $sort_options = [
            'date'       => __('Ordenar por las últimas', 'discount-rules-woo'),
            'popularity' => __('Ordenar por popularidad', 'discount-rules-woo'),
            'rating'     => __('Ordenar por calificación media', 'discount-rules-woo'),
            'price'      => __('Ordenar por precio: bajo a alto', 'discount-rules-woo'),
            'price-desc' => __('Ordenar por precio: alto a bajo', 'discount-rules-woo'),
            'discount'   => __('Ordenar por mayor descuento', 'discount-rules-woo'),
        ];

        $sort_opts = '';
        foreach ($sort_options as $value => $label) {
            $sort_opts .= sprintf(
                '<option value="%s"%s>%s</option>',
                esc_attr($value),
                selected($value, $orderby, false),
                esc_html($label)
            );
        }

        $clear_btn = sprintf(
            '<button type="button" class="drw-clear-filters" aria-label="%s">&#10005; %s</button>',
            esc_attr__('Limpiar filtro de categoría', 'discount-rules-woo'),
            esc_html__('Limpiar', 'discount-rules-woo')
        );

        return sprintf(
            '<div class="drw-sort-bar"><span class="drw-results-count">%s</span><div class="drw-sort-controls"><select class="drw-cat-select" aria-label="%s">%s</select><select class="drw-sort-select" aria-label="%s">%s</select>%s</div></div>',
            $count_text,
            esc_attr__('Filtrar por categoría', 'discount-rules-woo'),
            $cat_opts,
            esc_attr__('Ordenar productos', 'discount-rules-woo'),
            $sort_opts,
            $clear_btn
        );
    }

    /**
     * Map orderby slug to WP_Query order arguments.
     */
    private function get_orderby_args($orderby)
    {
        switch ($orderby) {
            case 'popularity':
                return ['orderby' => 'meta_value_num', 'meta_key' => 'total_sales', 'order' => 'DESC'];
            case 'rating':
                return ['orderby' => 'meta_value_num', 'meta_key' => '_wc_average_rating', 'order' => 'DESC'];
            case 'price':
                return ['orderby' => 'meta_value_num', 'meta_key' => '_price', 'order' => 'ASC'];
            case 'price-desc':
                return ['orderby' => 'meta_value_num', 'meta_key' => '_price', 'order' => 'DESC'];
            default: // date, discount (discount is sorted post-filter)
                return ['orderby' => 'date', 'order' => 'DESC'];
        }
    }

    /**
     * AJAX handler for loading more sale items or resorting.
     */
    public function ajax_load_sale_items()
    {
        check_ajax_referer('drw_sale_items', 'nonce');

        $page       = max(1, absint($_POST['page'] ?? 1));
        $per_page   = min(48, max(4, absint($_POST['per_page'] ?? 12)));
        $category   = sanitize_text_field($_POST['category'] ?? '');
        $orderby    = sanitize_key($_POST['orderby'] ?? 'date');
        $scan_limit = min(1000, max($per_page, absint($_POST['scan_limit'] ?? 500)));
        $ids        = $this->parse_id_list($_POST['ids'] ?? '');

        $all_cards = $this->collect_sale_cards($ids, $category, $scan_limit, $orderby);
        $total     = count($all_cards);
        $offset    = ($page - 1) * $per_page;
        $slice     = array_slice($all_cards, $offset, $per_page);

        wp_send_json_success([
            'html'     => implode('', $slice),
            'has_more' => ($offset + $per_page) < $total,
            'total'    => $total,
            'page'     => $page,
        ]);
    }

    /**
     * Normalize comma-separated product IDs.
     */
    private function parse_id_list($value)
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = explode(',', (string)$value);
        }

        $ids = [];
        foreach ($items as $item) {
            $id = (int)$item;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
