<?php

namespace Drw\App\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for the promo type catalogue.
 *
 * Replaces the two catalogues that used to drift independently:
 * PromosController::type_definitions() (PHP) and PROMO_TYPES (admin-promos.js).
 * PHP reads it directly; the admin UI receives it via wp_localize_script
 * (AdminController::enqueue_admin_assets) so both sides share one definition.
 */
class PromoTypeRegistry {

	/**
	 * Memoized, id-indexed catalogue.
	 *
	 * @var array|null
	 */
	private static $types = null;

	/**
	 * Return all type definitions, in catalogue order.
	 *
	 * @return array[]
	 */
	public static function all() {
		return array_values( self::definitions() );
	}

	/**
	 * Return the list of valid type ids.
	 *
	 * @return string[]
	 */
	public static function ids() {
		return array_keys( self::definitions() );
	}

	/**
	 * Get a single type definition by id.
	 *
	 * @param string $id Type id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$definitions = self::definitions();
		return isset( $definitions[ $id ] ) ? $definitions[ $id ] : null;
	}

	/**
	 * Whether a type exists in the catalogue.
	 *
	 * @param string $id Type id.
	 * @return bool
	 */
	public static function exists( $id ) {
		return null !== self::get( $id );
	}

	/**
	 * Whether a type requires a redeemable code.
	 *
	 * @param string $id Type id.
	 * @return bool
	 */
	public static function needs_code( $id ) {
		$type = self::get( $id );
		return $type ? (bool) $type['needsCode'] : false;
	}

	/**
	 * Build (and memoize) the catalogue, indexed by id.
	 *
	 * @return array
	 */
	private static function definitions() {
		if ( null !== self::$types ) {
			return self::$types;
		}

		$types = array(
			array(
				'id'        => 'percent',
				'label'     => __( 'Descuento porcentual', 'discount-rules-woo' ),
				'short'     => __( '% OFF', 'discount-rules-woo' ),
				'icon'      => 'tag',
				'color'     => '#5b7b41',
				'needsCode' => true,
				'valueType' => 'percent',
			),
			array(
				'id'        => 'fixed',
				'label'     => __( 'Descuento fijo', 'discount-rules-woo' ),
				'short'     => __( '$ OFF', 'discount-rules-woo' ),
				'icon'      => 'tag',
				'color'     => '#3a5a2a',
				'needsCode' => true,
				'valueType' => 'currency',
			),
			array(
				'id'        => 'launch',
				'label'     => __( 'Precio de lanzamiento', 'discount-rules-woo' ),
				'short'     => __( 'Lanzamiento', 'discount-rules-woo' ),
				'icon'      => 'star-filled',
				'color'     => '#00a000',
				'needsCode' => false,
				'valueType' => 'currency',
			),
			array(
				'id'        => '2x1',
				'label'     => __( '2x1', 'discount-rules-woo' ),
				'short'     => __( '2x1', 'discount-rules-woo' ),
				'icon'      => 'archive',
				'color'     => '#00c0b4',
				'needsCode' => false,
				'valueType' => 'none',
			),
			array(
				'id'        => '3x2',
				'label'     => __( '3x2', 'discount-rules-woo' ),
				'short'     => __( '3x2', 'discount-rules-woo' ),
				'icon'      => 'archive',
				'color'     => '#00c0b4',
				'needsCode' => false,
				'valueType' => 'none',
			),
			array(
				'id'        => 'second_unit',
				'label'     => __( 'Segunda unidad', 'discount-rules-woo' ),
				'short'     => __( '2ª und.', 'discount-rules-woo' ),
				'icon'      => 'archive',
				'color'     => '#008cd4',
				'needsCode' => false,
				'valueType' => 'percent',
			),
			array(
				'id'        => 'tiered',
				'label'     => __( 'Escalonado por monto', 'discount-rules-woo' ),
				'short'     => __( 'Escalonado', 'discount-rules-woo' ),
				'icon'      => 'chart-bar',
				'color'     => '#1d5c9e',
				'needsCode' => false,
				'valueType' => 'percent',
			),
			array(
				'id'        => 'bundle',
				'label'     => __( 'Bundle / combo', 'discount-rules-woo' ),
				'short'     => __( 'Combo', 'discount-rules-woo' ),
				'icon'      => 'screenoptions',
				'color'     => '#8a32a2',
				'needsCode' => false,
				'valueType' => 'currency',
			),
			array(
				'id'        => 'free_ship_threshold',
				'label'     => __( 'Envío gratis con umbral', 'discount-rules-woo' ),
				'short'     => __( 'Envío', 'discount-rules-woo' ),
				'icon'      => 'car',
				'color'     => '#bb8855',
				'needsCode' => false,
				'valueType' => 'currency',
			),
			array(
				'id'        => 'free_ship',
				'label'     => __( 'Envío gratis', 'discount-rules-woo' ),
				'short'     => __( 'Envío', 'discount-rules-woo' ),
				'icon'      => 'car',
				'color'     => '#bb8855',
				'needsCode' => true,
				'valueType' => 'none',
			),
			array(
				'id'        => 'welcome',
				'label'     => __( 'Cupón de bienvenida', 'discount-rules-woo' ),
				'short'     => __( 'Bienvenida', 'discount-rules-woo' ),
				'icon'      => 'star-filled',
				'color'     => '#d4af37',
				'needsCode' => true,
				'valueType' => 'percent',
			),
			array(
				'id'        => 'gift',
				'label'     => __( 'Regalo por compra', 'discount-rules-woo' ),
				'short'     => __( 'Regalo', 'discount-rules-woo' ),
				'icon'      => 'cart',
				'color'     => '#ff1a80',
				'needsCode' => false,
				'valueType' => 'text',
			),
			array(
				'id'        => 'cashback',
				'label'     => __( 'Puntos / cashback', 'discount-rules-woo' ),
				'short'     => __( 'Cashback', 'discount-rules-woo' ),
				'icon'      => 'star-filled',
				'color'     => '#7a3fa8',
				'needsCode' => false,
				'valueType' => 'percent',
			),
			array(
				'id'        => 'flash',
				'label'     => __( 'Oferta flash con contador', 'discount-rules-woo' ),
				'short'     => __( 'Flash', 'discount-rules-woo' ),
				'icon'      => 'update',
				'color'     => '#b8412a',
				'needsCode' => false,
				'valueType' => 'percent',
			),
			array(
				'id'        => 'data_capture',
				'label'     => __( 'Descuento por datos', 'discount-rules-woo' ),
				'short'     => __( 'Datos', 'discount-rules-woo' ),
				'icon'      => 'groups',
				'color'     => '#0b7a55',
				'needsCode' => true,
				'valueType' => 'percent',
			),
		);

		$indexed = array();
		foreach ( $types as $type ) {
			$indexed[ $type['id'] ] = $type;
		}

		self::$types = $indexed;
		return self::$types;
	}
}
