<?php

namespace Drw\App\Controllers;

use Drw\App\Models\PromoTypeRegistry;
use Drw\App\Models\PromoModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for managing promotional offers / coupons.
 *
 * Storage: the per-row `{prefix}drw_promos` table, accessed exclusively through
 * Drw\App\Models\PromoModel (atomic row operations, no read-modify-write blob).
 *
 * The REST contract is camelCase with Y-m-d dates (name, code, type, value,
 * scope, minAmount, limitGlobal, limitUser, uses, start, end, active, home,
 * priority, cartMessage, giftText). PromoModel speaks snake_case columns with
 * DATETIME dates and JSON envelopes for scope / gift_config. The two private
 * mappers to_columns()/to_rest() translate between the two shapes so the public
 * contract stays identical to the previous wp_options-backed implementation.
 */
class PromosController {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	// ------------------------------------------------------------------
	// Route registration
	// ------------------------------------------------------------------

	/**
	 * Register REST endpoints under drw/v1.
	 */
	public function register_routes() {
		$namespace = 'drw/v1';

		// GET  /promos      – list all
		// POST /promos      – create
		register_rest_route(
			$namespace,
			'/promos',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_promos' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_promo' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// PUT    /promos/<id>  – update
		// DELETE /promos/<id>  – delete
		register_rest_route(
			$namespace,
			'/promos/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_promo' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'validate_callback' => function ( $param ) {
								return is_numeric( $param );
							},
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_promo' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'validate_callback' => function ( $param ) {
								return is_numeric( $param );
							},
						),
					),
				),
			)
		);

		// POST /promos/<id>/toggle – toggle active flag
		register_rest_route(
			$namespace,
			'/promos/(?P<id>\d+)/toggle',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'toggle_promo' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'validate_callback' => function ( $param ) {
								return is_numeric( $param );
							},
						),
					),
				),
			)
		);

		// GET /promos/types – type definitions catalogue
		register_rest_route(
			$namespace,
			'/promos/types',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_types' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// GET /promos/check-code – live code-uniqueness check (admin UI helper).
		// Registered as a static path, so it never collides with the numeric
		// /promos/(?P<id>\d+) route above regardless of registration order.
		register_rest_route(
			$namespace,
			'/promos/check-code',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'check_code_availability' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'code'    => array(
							'required' => true,
						),
						'exclude' => array(
							'validate_callback' => function ( $param ) {
								return '' === $param || null === $param || is_numeric( $param );
							},
						),
					),
				),
			)
		);
	}

	// ------------------------------------------------------------------
	// Permission
	// ------------------------------------------------------------------

	/**
	 * Require manage_woocommerce capability.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	// ------------------------------------------------------------------
	// Endpoint callbacks
	// ------------------------------------------------------------------

	/**
	 * GET /drw/v1/promos
	 *
	 * @return \WP_REST_Response
	 */
	public function get_promos() {
		$rows   = PromoModel::get_all_promos();
		$promos = array();
		foreach ( $rows as $row ) {
			$promos[] = $this->to_rest( $row );
		}
		return new \WP_REST_Response( $promos, 200 );
	}

	/**
	 * POST /drw/v1/promos
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function create_promo( $request ) {
		$data = $this->get_request_data( $request );

		$validated = $this->validate_promo( $data );
		if ( is_wp_error( $validated ) ) {
			return $this->validation_error_response( $validated );
		}

		$new_id = PromoModel::insert( $this->to_columns( $validated ) );
		if ( ! $new_id ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Could not create the promo.', 'discount-rules-woo' ) ),
				500
			);
		}

		$promo = PromoModel::get_promo( $new_id );
		if ( null === $promo ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Could not create the promo.', 'discount-rules-woo' ) ),
				500
			);
		}

		// Compile the freshly stored promo into a real, engine-visible discount
		// (native WC_Coupon or wp_drw_rules row). Failures are logged, never fatal.
		$this->sync_bridge( $new_id, 'compile' );

		return new \WP_REST_Response( $this->to_rest( $promo ), 201 );
	}

	/**
	 * PUT /drw/v1/promos/<id>
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function update_promo( $request ) {
		$id       = (int) $request['id'];
		$existing = PromoModel::get_promo( $id );

		if ( null === $existing ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Promo not found', 'discount-rules-woo' ) ),
				404
			);
		}

		$data      = $this->get_request_data( $request );
		$validated = $this->validate_promo( $data, true, $id );
		if ( is_wp_error( $validated ) ) {
			return $this->validation_error_response( $validated );
		}

		// to_columns() intentionally omits uses / id, so the existing usage
		// counter and primary key are preserved by the row update.
		PromoModel::update( $id, $this->to_columns( $validated ) );

		$promo = PromoModel::get_promo( $id );
		if ( null === $promo ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Promo not found', 'discount-rules-woo' ) ),
				404
			);
		}

		// Re-sync the engine-visible discount with the updated promo definition.
		$this->sync_bridge( $id, 'compile' );

		return new \WP_REST_Response( $this->to_rest( $promo ), 200 );
	}

	/**
	 * DELETE /drw/v1/promos/<id>
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function delete_promo( $request ) {
		$id       = (int) $request['id'];
		$existing = PromoModel::get_promo( $id );

		if ( null === $existing ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Promo not found', 'discount-rules-woo' ) ),
				404
			);
		}

		// Remove the engine-visible discount (WC_Coupon / drw_rules row) BEFORE the
		// row is soft-deleted: decompile() resolves the promo via PromoModel, which
		// filters out deleted rows, so it must run while the promo is still live.
		$this->sync_bridge( $id, 'decompile' );

		PromoModel::delete( $id );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Promo deleted', 'discount-rules-woo' ),
			),
			200
		);
	}

	/**
	 * POST /drw/v1/promos/<id>/toggle
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function toggle_promo( $request ) {
		$id       = (int) $request['id'];
		$existing = PromoModel::get_promo( $id );

		if ( null === $existing ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Promo not found', 'discount-rules-woo' ) ),
				404
			);
		}

		$new_active = empty( $existing['active'] ) ? 1 : 0;
		PromoModel::update( $id, array( 'active' => $new_active ) );

		// Activating publishes the discount to the engine; deactivating retracts it.
		$this->sync_bridge( $id, $new_active ? 'compile' : 'decompile' );

		$promo = PromoModel::get_promo( $id );
		if ( null === $promo ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Promo not found', 'discount-rules-woo' ) ),
				404
			);
		}

		return new \WP_REST_Response( $this->to_rest( $promo ), 200 );
	}

	/**
	 * GET /drw/v1/promos/types
	 *
	 * Returns the catalogue of supported promo types. Kept as a refresh
	 * endpoint; the admin UI receives the same data preloaded via
	 * wp_localize_script (see AdminController::enqueue_admin_assets).
	 *
	 * @return \WP_REST_Response
	 */
	public function get_types() {
		return new \WP_REST_Response( PromoTypeRegistry::all(), 200 );
	}

	/**
	 * GET /drw/v1/promos/check-code
	 *
	 * Live code-uniqueness check for the admin UI's CodeInput component.
	 * Deliberately read-only: it reuses find_duplicate_code() — the exact
	 * same check performed inside validate_promo() during create/update —
	 * so a "available" response here is guaranteed to match what a real
	 * save would accept, without creating/mutating any promo or coupon.
	 *
	 * Query args:
	 * - code    (string, required) Candidate code, any case/format.
	 * - exclude (int, optional)    Promo id to exclude (editing an existing promo).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Always 200; availability is signalled via body.
	 */
	public function check_code_availability( $request ) {
		$code = isset( $request['code'] ) ? strtoupper( sanitize_text_field( $request['code'] ) ) : '';

		$exclude_param = $request['exclude'];
		$exclude_id    = ( null !== $exclude_param && '' !== $exclude_param && is_numeric( $exclude_param ) )
			? (int) $exclude_param
			: null;

		if ( '' === $code ) {
			return new \WP_REST_Response(
				array(
					'available' => false,
					'message'   => __( 'Enter a code to check.', 'discount-rules-woo' ),
				),
				200
			);
		}

		if ( ! preg_match( '/^[A-Z0-9_]+$/', $code ) ) {
			return new \WP_REST_Response(
				array(
					'available' => false,
					'message'   => __( 'Code must be uppercase alphanumeric with underscores only.', 'discount-rules-woo' ),
				),
				200
			);
		}

		$duplicate = $this->find_duplicate_code( $code, $exclude_id );
		if ( $duplicate ) {
			return new \WP_REST_Response(
				array(
					'available' => false,
					'message'   => $duplicate,
				),
				200
			);
		}

		return new \WP_REST_Response(
			array(
				'available' => true,
				'message'   => __( 'Code is available.', 'discount-rules-woo' ),
			),
			200
		);
	}

	// ------------------------------------------------------------------
	// Discount engine bridge
	// ------------------------------------------------------------------

	/**
	 * Compile/decompile a persisted promo into (or out of) the discount engine
	 * via PromoBridgeController.
	 *
	 * The promo row has already been written successfully by the time this runs,
	 * so a failure here (e.g. WooCommerce / WC_Coupon not loaded, a DB hiccup in
	 * the rules table) must NOT turn a successful save into a failed REST call.
	 * We therefore swallow and log any Throwable and let the endpoint return its
	 * normal response. \Throwable is caught deliberately so a fatal "class not
	 * found" (WooCommerce absent) is contained as well.
	 *
	 * @param int    $id     Promo primary key.
	 * @param string $action Either 'compile' or 'decompile'.
	 * @return void
	 */
	private function sync_bridge( $id, $action ) {
		try {
			$bridge = new PromoBridgeController();
			if ( 'decompile' === $action ) {
				$bridge->decompile( (int) $id );
			} else {
				$bridge->compile( (int) $id );
			}
		} catch ( \Throwable $e ) {
			error_log(
				sprintf(
					'[discount-rules-woo] Promo bridge %s failed for promo #%d: %s',
					$action,
					(int) $id,
					$e->getMessage()
				)
			);
		}
	}

	// ------------------------------------------------------------------
	// REST <-> table mapping
	// ------------------------------------------------------------------

	/**
	 * Map a validated, camelCase REST payload to the snake_case column shape
	 * expected by PromoModel::insert()/update().
	 *
	 * - Dates: the public Y-m-d string is written straight into the DATETIME
	 *   columns (MySQL widens 'Y-m-d' to 'Y-m-d 00:00:00'); '' becomes NULL.
	 * - scope: the free-form string is wrapped as { "raw": "<text>" } and JSON
	 *   encoded by PromoModel (mirrors the { target, raw } envelope the legacy
	 *   migration writes; both expose the original string under `raw`).
	 * - giftText: wrapped as { "text": "<value>" } inside gift_config.
	 * - Empty code becomes NULL so multiple codeless promos don't collide on the
	 *   UNIQUE(code) index.
	 * - `uses`, `id`, `priority` are deliberately omitted: uses/id are managed by
	 *   the model, and there is no priority column in the table.
	 *
	 * @param array $data Validated camelCase promo array.
	 * @return array Column-shaped data for PromoModel.
	 */
	private function to_columns( $data ) {
		return array(
			'name'         => $data['name'],
			'code'         => '' !== $data['code'] ? $data['code'] : null,
			'type'         => $data['type'],
			'value'        => $data['value'],
			'scope'        => $this->scope_to_storage( $data['scope'] ),
			'min_amount'   => $data['minAmount'],
			'limit_global' => $data['limitGlobal'] ? (int) $data['limitGlobal'] : null,
			'limit_user'   => $data['limitUser'] ? (int) $data['limitUser'] : null,
			'date_from'    => '' !== $data['start'] ? $data['start'] : null,
			'date_to'      => '' !== $data['end'] ? $data['end'] : null,
			'active'       => $data['active'] ? 1 : 0,
			'home'         => $data['home'] ? 1 : 0,
			'cart_message' => $data['cartMessage'],
			'gift_config'  => array( 'text' => $data['giftText'] ),
		);
	}

	/**
	 * Map a PromoModel row (snake_case columns, JSON already decoded by
	 * PromoModel::format_promo()) to the public camelCase REST shape.
	 *
	 * - Dates: DATETIME is truncated back to Y-m-d; NULL becomes ''.
	 * - scope: unwrapped from its { raw: ... } / { target, raw } envelope back to
	 *   the original string.
	 * - giftText: unwrapped from gift_config { text: ... }.
	 * - priority: the table has no priority column, so it is reported with the
	 *   default (1). It does not round-trip through storage.
	 *
	 * @param array $row Formatted PromoModel row.
	 * @return array Public REST promo.
	 */
	private function to_rest( $row ) {
		$scope = isset( $row['scope'] ) ? $row['scope'] : null;
		if ( is_array( $scope ) ) {
			if ( array_key_exists( 'product_ids', $scope ) || array_key_exists( 'category_ids', $scope ) || array_key_exists( 'ids', $scope ) ) {
				// Structured scope. Collapse the engine-native
				// { target, product_ids, category_ids } envelope (and tolerate a
				// bare { target, ids }) back into the compact { target, ids }
				// object the wizard's Paso 1 picker expects. The bridge's plural
				// 'categories' target maps to the wizard's singular 'category'.
				$stored_target = isset( $scope['target'] ) ? (string) $scope['target'] : 'all';

				if ( 'products' === $stored_target ) {
					$target = 'products';
					$ids    = ( isset( $scope['product_ids'] ) && is_array( $scope['product_ids'] ) )
						? $scope['product_ids']
						: ( ( isset( $scope['ids'] ) && is_array( $scope['ids'] ) ) ? $scope['ids'] : array() );
				} elseif ( 'categories' === $stored_target || 'category' === $stored_target ) {
					$target = 'category';
					$ids    = ( isset( $scope['category_ids'] ) && is_array( $scope['category_ids'] ) )
						? $scope['category_ids']
						: ( ( isset( $scope['ids'] ) && is_array( $scope['ids'] ) ) ? $scope['ids'] : array() );
				} else {
					$target = 'all';
					$ids    = array();
				}

				$scope = array(
					'target' => $target,
					'ids'    => array_values( array_map( 'intval', $ids ) ),
				);
			} elseif ( isset( $scope['raw'] ) ) {
				// Legacy / migration envelope { raw } or { target, raw } — the
				// original free-form string lives under `raw`.
				$scope = (string) $scope['raw'];
			} else {
				$scope = '';
			}
		} elseif ( ! is_string( $scope ) ) {
			$scope = '';
		}

		$gift      = isset( $row['gift_config'] ) ? $row['gift_config'] : null;
		$gift_text = ( is_array( $gift ) && isset( $gift['text'] ) ) ? (string) $gift['text'] : '';

		return array(
			'id'          => (int) $row['id'],
			'name'        => isset( $row['name'] ) ? (string) $row['name'] : '',
			'code'        => ( isset( $row['code'] ) && null !== $row['code'] ) ? (string) $row['code'] : '',
			'type'        => isset( $row['type'] ) ? (string) $row['type'] : '',
			'value'       => isset( $row['value'] ) ? (float) $row['value'] : 0,
			'scope'       => $scope,
			'minAmount'   => isset( $row['min_amount'] ) ? (float) $row['min_amount'] : 0,
			'limitGlobal' => isset( $row['limit_global'] ) ? (int) $row['limit_global'] : 0,
			'limitUser'   => isset( $row['limit_user'] ) ? (int) $row['limit_user'] : 0,
			'uses'        => isset( $row['uses'] ) ? (int) $row['uses'] : 0,
			'start'       => ! empty( $row['date_from'] ) ? substr( $row['date_from'], 0, 10 ) : '',
			'end'         => ! empty( $row['date_to'] ) ? substr( $row['date_to'], 0, 10 ) : '',
			'active'      => ! empty( $row['active'] ),
			'home'        => ! empty( $row['home'] ),
			'priority'    => 1,
			'cartMessage' => ( isset( $row['cart_message'] ) && null !== $row['cart_message'] ) ? (string) $row['cart_message'] : '',
			'giftText'    => $gift_text,
		);
	}

	/**
	 * Normalise the `scope` field, accepting BOTH shapes the front-end may send:
	 *
	 *   - New structured object: { target: 'all'|'products'|'category', ids: int[] }
	 *     produced by the wizard's Paso 1 (DrwProductCategoryPicker). Unknown
	 *     targets fall back to 'all'; ids are cast to unique positive ints and
	 *     cleared entirely when target is 'all'.
	 *   - Legacy free-form string (older clients / classic "Modo experto" editor),
	 *     kept as a sanitised string so pre-wizard promos keep round-tripping.
	 *
	 * @param mixed $raw Raw scope value from the request.
	 * @return array|string { target, ids } array, or a sanitised legacy string.
	 */
	private function sanitize_scope( $raw ) {
		if ( is_array( $raw ) && ( isset( $raw['target'] ) || isset( $raw['ids'] ) ) ) {
			$target = isset( $raw['target'] ) ? sanitize_key( $raw['target'] ) : 'all';
			if ( ! in_array( $target, array( 'all', 'products', 'category' ), true ) ) {
				$target = 'all';
			}

			$ids = array();
			if ( 'all' !== $target && isset( $raw['ids'] ) && is_array( $raw['ids'] ) ) {
				foreach ( $raw['ids'] as $id ) {
					$id = absint( $id );
					if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
						$ids[] = $id;
					}
				}
			}

			return array(
				'target' => $target,
				'ids'    => $ids,
			);
		}

		return sanitize_text_field( is_scalar( $raw ) ? (string) $raw : '' );
	}

	/**
	 * Wrap a validated scope value for JSON storage via PromoModel.
	 *
	 * The public REST contract is the compact { target, ids } object, but the
	 * discount engine (PromoBridgeController::derive_target()) reads the richer
	 * { target, product_ids, category_ids } envelope with the plural
	 * 'categories' target. We translate to that engine-native shape here so a
	 * scoped promo actually filters the discount (real scope, not decorative);
	 * to_rest() translates back to { target, ids } for the client. Legacy
	 * strings keep the historical { raw: "<text>" } envelope untouched.
	 *
	 * @param array|string $scope Validated scope (from sanitize_scope()).
	 * @return array Column value for the JSON `scope` field.
	 */
	private function scope_to_storage( $scope ) {
		if ( is_array( $scope ) ) {
			$target = isset( $scope['target'] ) ? (string) $scope['target'] : 'all';
			$ids    = ( isset( $scope['ids'] ) && is_array( $scope['ids'] ) )
				? array_values( array_map( 'absint', $scope['ids'] ) )
				: array();

			if ( 'products' === $target ) {
				return array(
					'target'       => 'products',
					'product_ids'  => $ids,
					'category_ids' => array(),
				);
			}

			if ( 'category' === $target || 'categories' === $target ) {
				return array(
					'target'       => 'categories',
					'product_ids'  => array(),
					'category_ids' => $ids,
				);
			}

			return array(
				'target'       => 'all',
				'product_ids'  => array(),
				'category_ids' => array(),
			);
		}

		return array( 'raw' => (string) $scope );
	}

	// ------------------------------------------------------------------
	// Validation & sanitisation
	// ------------------------------------------------------------------

	/**
	 * Extract JSON or body params from the request.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return array
	 */
	private function get_request_data( $request ) {
		$data = $request->get_json_params();
		if ( empty( $data ) ) {
			$data = $request->get_body_params();
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Validate and sanitise promo payload.
	 *
	 * @param array    $data       Raw input data.
	 * @param bool     $is_update  Whether this is an update (relaxed required checks).
	 * @param int|null $exclude_id Promo id to exclude from code-uniqueness checks (the promo being updated).
	 * @return array|\WP_Error Sanitised promo array or error.
	 */
	private function validate_promo( $data, $is_update = false, $exclude_id = null ) {

		// --- name -----------------------------------------------------------
		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		if ( strlen( $name ) < 3 ) {
			return new \WP_Error(
				'invalid_name',
				__( 'Name is required and must be at least 3 characters.', 'discount-rules-woo' ),
				array( 'field' => 'name', 'status' => 400 )
			);
		}

		// --- type -----------------------------------------------------------
		$type = isset( $data['type'] ) ? sanitize_text_field( $data['type'] ) : '';
		if ( ! PromoTypeRegistry::exists( $type ) ) {
			return new \WP_Error(
				'invalid_type',
				/* translators: %s: comma-separated list of valid types */
				sprintf( __( 'Invalid type. Allowed: %s', 'discount-rules-woo' ), implode( ', ', PromoTypeRegistry::ids() ) ),
				array( 'field' => 'type', 'status' => 400 )
			);
		}

		// --- code -------------------------------------------------------------
		$code = isset( $data['code'] ) ? strtoupper( sanitize_text_field( $data['code'] ) ) : '';
		if ( '' !== $code && ! preg_match( '/^[A-Z0-9_]+$/', $code ) ) {
			return new \WP_Error(
				'invalid_code',
				__( 'Code must be uppercase alphanumeric with underscores only.', 'discount-rules-woo' ),
				array( 'field' => 'code', 'status' => 400 )
			);
		}

		if ( PromoTypeRegistry::needs_code( $type ) && '' === $code ) {
			return new \WP_Error(
				'code_required',
				__( 'This promo type requires a redeemable code.', 'discount-rules-woo' ),
				array( 'field' => 'code', 'status' => 400 )
			);
		}

		if ( '' !== $code ) {
			$duplicate = $this->find_duplicate_code( $code, $exclude_id );
			if ( $duplicate ) {
				return new \WP_Error(
					'duplicate_code',
					$duplicate,
					array( 'field' => 'code', 'status' => 400 )
				);
			}
		}

		// --- value ----------------------------------------------------------
		$value = isset( $data['value'] ) ? floatval( $data['value'] ) : 0;
		if ( $value < 0 ) {
			return new \WP_Error(
				'invalid_value',
				__( 'Value must be zero or positive.', 'discount-rules-woo' ),
				array( 'field' => 'value', 'status' => 400 )
			);
		}
		if ( 'percent' === $type && $value > 100 ) {
			return new \WP_Error(
				'invalid_percent',
				__( 'Percentage value cannot exceed 100.', 'discount-rules-woo' ),
				array( 'field' => 'value', 'status' => 400 )
			);
		}
		if ( 'cashback' === $type && $value > 100 ) {
			return new \WP_Error(
				'invalid_cashback',
				__( 'Cashback percentage cannot exceed 100.', 'discount-rules-woo' ),
				array( 'field' => 'value', 'status' => 400 )
			);
		}

		// --- dates ----------------------------------------------------------
		$start = isset( $data['start'] ) ? sanitize_text_field( $data['start'] ) : '';
		if ( '' !== $start && ! $this->is_valid_date( $start ) ) {
			return new \WP_Error(
				'invalid_start_date',
				__( 'Start date must be in Y-m-d format.', 'discount-rules-woo' ),
				array( 'field' => 'start', 'status' => 400 )
			);
		}

		$end = isset( $data['end'] ) ? sanitize_text_field( $data['end'] ) : '';
		if ( '' !== $end && ! $this->is_valid_date( $end ) ) {
			return new \WP_Error(
				'invalid_end_date',
				__( 'End date must be in Y-m-d format.', 'discount-rules-woo' ),
				array( 'field' => 'end', 'status' => 400 )
			);
		}

		if ( '' !== $start && '' !== $end && $end < $start ) {
			return new \WP_Error(
				'invalid_date_range',
				__( 'End date must be on or after the start date.', 'discount-rules-woo' ),
				array( 'field' => 'end', 'status' => 400 )
			);
		}

		// --- numeric limits -------------------------------------------------
		$min_amount   = isset( $data['minAmount'] ) ? floatval( $data['minAmount'] ) : 0;
		$limit_global = isset( $data['limitGlobal'] ) ? absint( $data['limitGlobal'] ) : 0;
		$limit_user   = isset( $data['limitUser'] ) ? absint( $data['limitUser'] ) : 0;
		$priority     = isset( $data['priority'] ) ? absint( $data['priority'] ) : 1;
		$priority     = max( 1, min( 10, $priority ) );

		// --- booleans -------------------------------------------------------
		$active = isset( $data['active'] ) ? (bool) $data['active'] : true;
		$home   = isset( $data['home'] ) ? (bool) $data['home'] : false;

		// --- scope ----------------------------------------------------------
		// Accepts BOTH the new structured { target, ids } object (produced by
		// the wizard's Paso 1 / DrwProductCategoryPicker) and the legacy
		// free-form string, so promos saved before the wizard keep working.
		$scope = $this->sanitize_scope( isset( $data['scope'] ) ? $data['scope'] : '' );

		// --- text fields ----------------------------------------------------
		$cart_message = isset( $data['cartMessage'] ) ? sanitize_text_field( $data['cartMessage'] ) : '';
		$gift_text    = isset( $data['giftText'] ) ? sanitize_text_field( $data['giftText'] ) : '';

		return array(
			'name'        => $name,
			'code'        => $code,
			'type'        => $type,
			'value'       => $value,
			'scope'       => $scope,
			'minAmount'   => $min_amount,
			'limitGlobal' => $limit_global,
			'limitUser'   => $limit_user,
			'uses'        => 0,
			'start'       => $start,
			'end'         => $end,
			'active'      => $active,
			'home'        => $home,
			'priority'    => $priority,
			'cartMessage' => $cart_message,
			'giftText'    => $gift_text,
		);
	}

	/**
	 * Check whether a string is a valid Y-m-d date.
	 *
	 * @param string $date Date string.
	 * @return bool
	 */
	private function is_valid_date( $date ) {
		$d = \DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}

	/**
	 * Check a code against other promos and against native WooCommerce coupons.
	 *
	 * Uniqueness against other promos is delegated to PromoModel::code_exists()
	 * (a single indexed COUNT query on the promos table). Collisions with native
	 * WooCommerce coupons are still checked via wc_get_coupon_id_by_code().
	 *
	 * @param string   $code       Uppercase, already-sanitised code.
	 * @param int|null $exclude_id Promo id to exclude (the promo being updated).
	 * @return string|false Error message if the code collides, false if it's free.
	 */
	private function find_duplicate_code( $code, $exclude_id ) {
		if ( PromoModel::code_exists( $code, $exclude_id ) ) {
			return __( 'This code is already used by another promo.', 'discount-rules-woo' );
		}

		if ( function_exists( 'wc_get_coupon_id_by_code' ) && wc_get_coupon_id_by_code( $code ) ) {
			return __( 'This code is already used by an existing WooCommerce coupon.', 'discount-rules-woo' );
		}

		return false;
	}

	/**
	 * Build a structured 400 (or error-specified) response from a validation WP_Error.
	 *
	 * @param \WP_Error $error Validation error.
	 * @return \WP_REST_Response
	 */
	private function validation_error_response( \WP_Error $error ) {
		$data   = $error->get_error_data();
		$status = isset( $data['status'] ) ? (int) $data['status'] : 400;

		return new \WP_REST_Response(
			array(
				'message' => $error->get_error_message(),
				'code'    => $error->get_error_code(),
				'field'   => isset( $data['field'] ) ? $data['field'] : null,
			),
			$status
		);
	}

}
