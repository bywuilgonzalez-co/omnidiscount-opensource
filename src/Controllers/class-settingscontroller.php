<?php

namespace Drw\App\Controllers;

use Drw\App\Models\SettingsModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsController {
	private static $instance = null;

	/**
	 * Singleton instance.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Register REST API routes.
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register endpoints.
	 */
	public function register_routes() {
		$namespace = 'drw/v1';

		register_rest_route(
			$namespace,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/settings/reset',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reset_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/settings/types',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_discount_types' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/settings/conditions',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_conditions' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/settings/themes',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_themes' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Permission check: Require WooCommerce management capabilities.
	 */
	public function check_permission() {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	/**
	 * GET /drw/v1/settings
	 */
	public function get_settings( $request ) {
		$settings = SettingsModel::get_all_settings();
		return new \WP_REST_Response( $settings, 200 );
	}

	/**
	 * POST /drw/v1/settings
	 */
	public function save_settings( $request ) {
		$data = $request->get_json_params();

		if ( empty( $data ) || ! is_array( $data ) ) {
			return new \WP_REST_Response( array( 'error' => 'Invalid settings payload' ), 400 );
		}

		$errors = array();

		foreach ( $data as $key => $value ) {
			if ( ! SettingsModel::save_setting( $key, $value ) ) {
				$errors[ $key ] = 'Failed to save setting';
			}
		}

		if ( ! empty( $errors ) ) {
			return new \WP_REST_Response( array( 'errors' => $errors ), 400 );
		}

		return new \WP_REST_Response( SettingsModel::get_all_settings(), 200 );
	}

	/**
	 * POST /drw/v1/settings/reset
	 */
	public function reset_settings( $request ) {
		$defaults = SettingsModel::reset_to_defaults();
		return new \WP_REST_Response( $defaults, 200 );
	}

	/**
	 * GET /drw/v1/settings/types
	 */
	public function get_discount_types( $request ) {
		$settings = SettingsModel::get_all_settings();
		$types    = isset( $settings['discount_types'] ) ? $settings['discount_types'] : array();

		$result = array();
		foreach ( $types as $key => $config ) {
			$result[] = array(
				'id'      => $key,
				'label'   => $this->type_label( $key ),
				'enabled' => isset( $config['enabled'] ) ? $config['enabled'] : false,
			);
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /drw/v1/settings/conditions
	 */
	public function get_conditions( $request ) {
		$settings   = SettingsModel::get_all_settings();
		$conditions = isset( $settings['conditions'] ) ? $settings['conditions'] : array();

		$result = array();
		foreach ( $conditions as $key => $config ) {
			$result[] = array(
				'id'      => $key,
				'label'   => $this->condition_label( $key ),
				'enabled' => isset( $config['enabled'] ) ? $config['enabled'] : false,
			);
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /drw/v1/settings/themes
	 */
	public function get_themes( $request ) {
		return new \WP_REST_Response( SettingsModel::get_theme_presets(), 200 );
	}

	/**
	 * Get human-readable label for discount type.
	 */
	private function type_label( $type ) {
		$labels = array(
			'percentage'    => 'Percentage Discount',
			'fixed'         => 'Fixed Price Discount',
			'bulk'          => 'Bulk Tiered Discount',
			'bogo'          => 'Buy One Get One',
			'bundle_set'    => 'Bundle Set Pricing',
			'free_shipping' => 'Free Shipping',
		);

		return isset( $labels[ $type ] ) ? $labels[ $type ] : ucfirst( str_replace( '_', ' ', $type ) );
	}

	/**
	 * Get human-readable label for condition.
	 *
	 * Labels are returned in Spanish to match the rest of the OmniDiscount admin
	 * UI (this endpoint's only consumer is the "Condiciones y Filtros
	 * Habilitados" tab in admin-app.js). Wording mirrors the Rule Editor's
	 * condition-type dropdown so a condition reads the same in both places.
	 */
	private function condition_label( $condition ) {
		$labels = array(
			'cart_subtotal'                 => __( 'Subtotal del carrito', 'discount-rules-woo' ),
			'cart_items_quantity'           => __( 'Cantidad total de artículos', 'discount-rules-woo' ),
			'cart_items_weight'             => __( 'Peso total del carrito', 'discount-rules-woo' ),
			'cart_line_items_count'         => __( 'Número de líneas de producto', 'discount-rules-woo' ),
			'billing_city'                  => __( 'Ciudad de facturación', 'discount-rules-woo' ),
			'shipping_location'             => __( 'Dirección de envío', 'discount-rules-woo' ),
			'user_logged_in'                => __( 'Estado de sesión del usuario', 'discount-rules-woo' ),
			'user_email'                    => __( 'Correo del usuario', 'discount-rules-woo' ),
			'user_role'                     => __( 'Rol de usuario', 'discount-rules-woo' ),
			'user_list'                     => __( 'Lista de usuarios (IDs específicos)', 'discount-rules-woo' ),
			'cart_coupon'                   => __( 'Cupón aplicado en el carrito', 'discount-rules-woo' ),
			'products'                      => __( 'Productos', 'discount-rules-woo' ),
			'categories'                    => __( 'Categorías', 'discount-rules-woo' ),
			'cart_item_product_combination' => __( 'Combinación de productos/categorías', 'discount-rules-woo' ),
			'cart_item_product_onsale'      => __( 'Estado de productos en oferta', 'discount-rules-woo' ),
			'purchase_history'              => __( 'Historial de compras del cliente', 'discount-rules-woo' ),
			'order_date'                    => __( 'Programación (fechas/horas/días)', 'discount-rules-woo' ),
		);

		return isset( $labels[ $condition ] ) ? $labels[ $condition ] : ucfirst( str_replace( '_', ' ', $condition ) );
	}
}
