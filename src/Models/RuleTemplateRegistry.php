<?php

namespace Drw\App\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for the one-click "discount rule" template gallery.
 *
 * Mirrors the shape and memoization pattern of PromoTypeRegistry: PHP owns the
 * catalogue and the admin UI receives it verbatim via wp_localize_script
 * (AdminController::enqueue_admin_assets -> drwAdminData.ruleTemplates), so the
 * gallery in admin-app.js never keeps a second, drifting copy.
 *
 * Each template carries display metadata (id, label, description, icon, color)
 * plus a `rule` object that is a COMPLETE default for the RuleEditor: it matches
 * exactly the shape DrwApp.handleAddRule() builds for a blank rule
 * (title, enabled, exclusive, priority, apply_to, filters, conditions,
 * adjustments), only pre-filled. Selecting a template just seeds `editingRule`
 * with a deep copy of `rule`; the RuleEditor form itself is untouched.
 *
 * Icons are WordPress Dashicon names (the Reglas screen renders dashicons, not
 * Material Symbols). Colors reuse the PromoTypeRegistry palette so the whole
 * admin stays visually consistent.
 */
class RuleTemplateRegistry {

	/**
	 * Memoized, id-indexed catalogue.
	 *
	 * @var array|null
	 */
	private static $templates = null;

	/**
	 * Return all template definitions, in catalogue order.
	 *
	 * @return array[]
	 */
	public static function all() {
		return array_values( self::definitions() );
	}

	/**
	 * Return the list of valid template ids.
	 *
	 * @return string[]
	 */
	public static function ids() {
		return array_keys( self::definitions() );
	}

	/**
	 * Get a single template definition by id.
	 *
	 * @param string $id Template id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$definitions = self::definitions();
		return isset( $definitions[ $id ] ) ? $definitions[ $id ] : null;
	}

	/**
	 * Whether a template exists in the catalogue.
	 *
	 * @param string $id Template id.
	 * @return bool
	 */
	public static function exists( $id ) {
		return null !== self::get( $id );
	}

	/**
	 * The blank default filters block every template's `rule` starts from.
	 *
	 * Kept identical to DrwApp.handleAddRule()'s `filters` default so a template
	 * seeds the RuleEditor with exactly the structure it expects.
	 *
	 * @return array
	 */
	private static function default_filters() {
		return array(
			'product_ids'          => array(),
			'category_ids'         => array(),
			'exclude_product_ids'  => array(),
			'exclude_category_ids' => array(),
		);
	}

	/**
	 * Build (and memoize) the catalogue, indexed by id.
	 *
	 * @return array
	 */
	private static function definitions() {
		if ( null !== self::$templates ) {
			return self::$templates;
		}

		$templates = array(

			// 1. Bulk / volume tiers.
			array(
				'id'          => 'volume_discount',
				'label'       => __( 'Descuento por volumen', 'discount-rules-woo' ),
				'description' => __( 'Más unidades, mayor descuento. Ideal para incentivar compras grandes.', 'discount-rules-woo' ),
				'icon'        => 'chart-bar',
				'color'       => '#1d5c9e',
				'rule'        => array(
					'title'       => __( 'Descuento por volumen', 'discount-rules-woo' ),
					'enabled'     => true,
					'exclusive'   => false,
					'priority'    => 10,
					'apply_to'    => 'all_products',
					'filters'     => self::default_filters(),
					'conditions'  => array(),
					'adjustments' => array(
						'type'  => 'bulk',
						'value' => 10,
						'tiers' => array(
							array( 'min' => 3,  'max' => 5,  'type' => 'percentage', 'value' => 10 ),
							array( 'min' => 6,  'max' => 11, 'type' => 'percentage', 'value' => 15 ),
							array( 'min' => 12, 'max' => '', 'type' => 'percentage', 'value' => 20 ),
						),
					),
				),
			),

			// 2. Flat 20% on chosen categories.
			array(
				'id'          => 'category_percentage',
				'label'       => __( '20% en categoría', 'discount-rules-woo' ),
				'description' => __( 'Un 20% de descuento en las categorías que elijas. Selecciónalas al abrir la regla.', 'discount-rules-woo' ),
				'icon'        => 'category',
				'color'       => '#5b7b41',
				'rule'        => array(
					'title'       => __( '20% en categoría', 'discount-rules-woo' ),
					'enabled'     => true,
					'exclusive'   => false,
					'priority'    => 10,
					'apply_to'    => 'specific_categories',
					'filters'     => self::default_filters(),
					'conditions'  => array(),
					'adjustments' => array(
						'type'  => 'percentage',
						'value' => 20,
						'tiers' => array(),
					),
				),
			),

			// 3. Free shipping over a subtotal threshold.
			array(
				'id'          => 'free_shipping_threshold',
				'label'       => __( 'Envío gratis desde $150', 'discount-rules-woo' ),
				'description' => __( 'Ofrece envío gratis cuando el subtotal supera un umbral. Ajusta el monto a tu tienda.', 'discount-rules-woo' ),
				'icon'        => 'car',
				'color'       => '#bb8855',
				'rule'        => array(
					'title'       => __( 'Envío gratis desde $150', 'discount-rules-woo' ),
					'enabled'     => true,
					'exclusive'   => false,
					'priority'    => 10,
					'apply_to'    => 'all_products',
					'filters'     => self::default_filters(),
					'conditions'  => array(
						array(
							'type'          => 'subtotal',
							'operator'      => 'greater_than_or_equal',
							'value'         => 150,
							'location_type' => 'country',
							'check_type'    => 'total_quantity',
						),
					),
					'adjustments' => array(
						'type'  => 'free_shipping',
						'value' => 0,
						'tiers' => array(),
					),
				),
			),

			// 4. BOGO: compra 2, lleva 1 gratis.
			// NOTA: buy_qty=2/get_qty=1 significa "compra 2, lleva 1 más gratis"
			// (3 unidades por el precio de 2) -- eso es "3x2" en la convencion de
			// nombres del plugin (ver PromoTypeRegistry '3x2'), NO "2x1" (que
			// seria buy_qty=1/get_qty=1: comprar 1 y llevar 1 gratis). La etiqueta
			// se dejo generica a proposito para no comprometerse con un nombre
			// numerico que no coincida con los datos reales -- encontrado y
			// corregido via prueba real en navegador: NaturalLanguageSummary
			// describia correctamente "3x2" mientras la plantilla decia "2x1".
			array(
				'id'          => 'bogo_2x1',
				'label'       => __( 'BOGO: compra 2, lleva 1 gratis', 'discount-rules-woo' ),
				'description' => __( 'Compra 2 y lleva 1 gratis. Personaliza las cantidades y el producto de regalo.', 'discount-rules-woo' ),
				'icon'        => 'archive',
				'color'       => '#00c0b4',
				'rule'        => array(
					'title'       => __( 'BOGO: compra 2, lleva 1 gratis', 'discount-rules-woo' ),
					'enabled'     => true,
					'exclusive'   => false,
					'priority'    => 10,
					'apply_to'    => 'all_products',
					'filters'     => self::default_filters(),
					'conditions'  => array(),
					'adjustments' => array(
						'type'             => 'bogo',
						'value'            => 0,
						'buy_qty'          => 2,
						'get_qty'          => 1,
						'discount_type'    => 'free',
						'get_product_type' => 'same',
						'get_products'     => array(),
						'tiers'            => array(),
					),
				),
			),

			// 5. Wholesale (role-gated) percentage.
			array(
				'id'          => 'wholesale_role',
				'label'       => __( 'Descuento mayorista', 'discount-rules-woo' ),
				'description' => __( 'Un descuento reservado a ciertos roles de usuario. Elige los roles al abrir la regla.', 'discount-rules-woo' ),
				'icon'        => 'groups',
				'color'       => '#0b7a55',
				'rule'        => array(
					'title'       => __( 'Descuento mayorista', 'discount-rules-woo' ),
					'enabled'     => true,
					'exclusive'   => false,
					'priority'    => 10,
					'apply_to'    => 'all_products',
					'filters'     => self::default_filters(),
					'conditions'  => array(
						array(
							'type'          => 'user_role',
							'operator'      => 'in_list',
							'value'         => array(),
							'location_type' => 'country',
							'check_type'    => 'total_quantity',
						),
					),
					'adjustments' => array(
						'type'  => 'percentage',
						'value' => 15,
						'tiers' => array(),
					),
				),
			),

			// 6. First-purchase reward (no prior orders).
			array(
				'id'          => 'first_purchase',
				'label'       => __( 'Descuento primera compra', 'discount-rules-woo' ),
				'description' => __( 'Premia a los clientes nuevos: aplica solo si no tienen pedidos previos.', 'discount-rules-woo' ),
				'icon'        => 'star-filled',
				'color'       => '#d4af37',
				'rule'        => array(
					'title'       => __( 'Descuento primera compra', 'discount-rules-woo' ),
					'enabled'     => true,
					'exclusive'   => false,
					'priority'    => 10,
					'apply_to'    => 'all_products',
					'filters'     => self::default_filters(),
					'conditions'  => array(
						array(
							'type'           => 'purchase_history',
							'history_metric' => 'orders_count',
							'operator'       => 'less_than_or_equal',
							'value'          => 0,
							'location_type'  => 'country',
							'check_type'     => 'total_quantity',
						),
					),
					'adjustments' => array(
						'type'  => 'percentage',
						'value' => 10,
						'tiers' => array(),
					),
				),
			),
		);

		$indexed = array();
		foreach ( $templates as $template ) {
			$indexed[ $template['id'] ] = $template;
		}

		self::$templates = $indexed;
		return self::$templates;
	}
}
