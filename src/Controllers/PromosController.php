<?php

namespace Drw\App\Controllers;

use Drw\App\Models\PromoTypeRegistry;
use Drw\App\Models\PromoModel;
use Drw\App\Adjustments\Bogo;
use Drw\App\Adjustments\BundleSet;
use Drw\App\Adjustments\FreeShipping;

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

		// GET /promos/<id>/stats – usage / impact metrics for one promo
		register_rest_route(
			$namespace,
			'/promos/(?P<id>\d+)/stats',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_promo_stats' ),
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

		// POST /promos/<id>/sandbox – admin-only preview activation.
		// Does NOT flip the promo's `active` column (never published to real
		// customers); it issues a short-lived signed cookie scoped to the
		// current admin user only. See PromosController::activate_sandbox()
		// and PromoBridgeController::get_sandboxed_rule_for_current_user().
		register_rest_route(
			$namespace,
			'/promos/(?P<id>\d+)/sandbox',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'activate_sandbox' ),
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

		// POST /promos/check-conflicts – non-blocking pre-flight warnings for a
		// promo still being drafted in the wizard. Registered as a static path,
		// same reasoning as check-code above: never collides with the numeric
		// /promos/(?P<id>\d+) route regardless of registration order.
		register_rest_route(
			$namespace,
			'/promos/check-conflicts',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'check_conflicts' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// POST /promos/preview – dry-run before/after price for an UNSAVED promo
		// draft against a sample product. Static path (like check-code /
		// check-conflicts) so it never collides with /promos/(?P<id>\d+).
		// Strictly read-only: computes a price, persists nothing.
		register_rest_route(
			$namespace,
			'/promos/preview',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'preview_promo' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// GET  /promos/legacy-migration – status of the one-time legacy import
		//      (how many wp_options('drw_promos') entries exist, how many are
		//      already migrated). Purely informational, never writes anything.
		// POST /promos/legacy-migration – actually run
		//      PromoMigrationController::migrate_legacy_promos(). Safe to call
		//      more than once: it tracks already-migrated legacy ids and never
		//      re-inserts a row (see PromoMigrationController::MIGRATED_IDS_KEY).
		register_rest_route(
			$namespace,
			'/promos/legacy-migration',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_legacy_migration_status' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run_legacy_migration' ),
					'permission_callback' => array( $this, 'check_permission' ),
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
	 * POST /drw/v1/promos/<id>/sandbox
	 *
	 * "Sandbox mode": lets the CURRENT admin preview an inactive (unpublished)
	 * promo end-to-end in their own cart, without flipping the promo's
	 * `active` column and therefore WITHOUT ever exposing it to real
	 * customers. Instead of publishing anything, this issues a short-lived
	 * (30 min) signed cookie that scopes the override to:
	 *   - this one promo id,
	 *   - this one WP user id (re-checked against get_current_user_id() on
	 *     every read, so a copied/leaked cookie is inert for anyone else),
	 *   - a server-side expiry embedded in the signed payload.
	 *
	 * The promo row itself is never written to by this endpoint. The read
	 * side (PromoBridgeController::get_sandboxed_rule_for_current_user(),
	 * consumed by CartController) is what actually makes the promo *behave*
	 * as active, and only for this cookie's owner.
	 *
	 * Only supported for non-code (automatically-applied) promo types, i.e.
	 * those compiled into a wp_drw_rules row (PromoTypeRegistry::needs_code()
	 * === false). Code-based promos (percent/fixed/free_ship/welcome/
	 * data_capture) are redeemed by typing a code and are out of scope here.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function activate_sandbox( $request ) {
		$id    = (int) $request['id'];
		$promo = PromoModel::get_promo( $id );

		if ( null === $promo ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Promo not found', 'discount-rules-woo' ) ),
				404
			);
		}

		if ( PromoTypeRegistry::needs_code( $promo['type'] ) ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Sandbox preview is only available for automatic (non-code) promo types.', 'discount-rules-woo' ) ),
				400
			);
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			// Belt-and-braces: check_permission() already requires a
			// capability, which implies a logged-in user, but a signed
			// cookie with no owner would be meaningless.
			return new \WP_REST_Response(
				array( 'message' => __( 'You must be logged in to use sandbox mode.', 'discount-rules-woo' ) ),
				401
			);
		}

		$expires_at = time() + PromoBridgeController::SANDBOX_TTL;
		$token      = PromoBridgeController::build_sandbox_cookie_value( $id, $user_id, $expires_at );

		$this->set_sandbox_cookie( $token, $expires_at );

		return new \WP_REST_Response(
			array(
				'success'   => true,
				'promoId'   => $id,
				'expiresAt' => $expires_at,
				'message'   => __( 'Sandbox mode activated for your admin session only. This promo has NOT been published to customers.', 'discount-rules-woo' ),
			),
			200
		);
	}

	/**
	 * Write the signed sandbox token as an HttpOnly, SameSite=Strict cookie
	 * scoped to the site's own cookie path/domain. HttpOnly so client-side
	 * script (including any third-party script on the storefront) cannot
	 * read or exfiltrate it; Strict so it is never sent on cross-site
	 * navigations/requests.
	 *
	 * @param string $token      Signed "{promoId}:{userId}:{expiresAt}:{signature}" value.
	 * @param int    $expires_at Unix timestamp the cookie (and the signed payload) expire at.
	 * @return void
	 */
	private function set_sandbox_cookie( $token, $expires_at ) {
		$path   = defined( 'COOKIEPATH' ) ? COOKIEPATH : '/';
		$domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
		$secure = function_exists( 'is_ssl' ) ? is_ssl() : false;

		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie(
				PromoBridgeController::SANDBOX_COOKIE_NAME,
				$token,
				array(
					'expires'  => $expires_at,
					'path'     => $path,
					'domain'   => $domain,
					'secure'   => $secure,
					'httponly' => true,
					'samesite' => 'Strict',
				)
			);
		} else {
			// PHP < 7.3 has no SameSite param on setcookie(); append it to the
			// path the same way WordPress core does for its own auth cookies.
			setcookie(
				PromoBridgeController::SANDBOX_COOKIE_NAME,
				$token,
				$expires_at,
				$path . '; samesite=Strict',
				$domain,
				$secure,
				true
			);
		}
	}

	/**
	 * GET /drw/v1/promos/<id>/stats
	 *
	 * Usage / impact metrics for a single promo:
	 * - uses:            lifetime redemption counter from the promo row
	 *                    (maintained by PromoModel::increment_usage()).
	 * - discountTotal:   SUM(discount_amount) over wp_drw_order_discounts rows
	 *                    whose `details` JSON references this promo.
	 * - assistedRevenue: approximate revenue of the orders that carried this
	 *                    promo (sum of order totals via wc_get_order, capped).
	 * - byDay:           last-30-days daily series when dated data exists.
	 *
	 * IMPORTANT — real shape of the `details` column: today
	 * AnalyticsController::record_order_discounts() writes wp_json_encode([])
	 * (a bare empty JSON array), so no current row references a promo yet.
	 * Richer writers may later store entries like {"promo_id":5,...} or
	 * {"rule_id":12,...} (bridge-compiled promos). We therefore prefilter with
	 * targeted LIKE patterns and then VERIFY by decoding the JSON in PHP, so
	 * the endpoint returns honest zeros with today's data and picks up richer
	 * shapes automatically without assuming any fixed structure.
	 *
	 * The table's baseline schema (Database::create_tables()) also has no
	 * created_at column even though AnalyticsController writes one, so its
	 * existence is probed before building the daily series.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_promo_stats( $request ) {
		global $wpdb;

		$id    = (int) $request['id'];
		$promo = PromoModel::get_promo( $id );

		if ( null === $promo ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Promo not found', 'discount-rules-woo' ) ),
				404
			);
		}

		$table   = esc_sql( $wpdb->prefix . 'drw_order_discounts' );
		$rule_id = ! empty( $promo['rule_id'] ) ? (int) $promo['rule_id'] : 0;

		// Probe optional created_at column (absent in the baseline schema).
		$has_created_at = (bool) $wpdb->get_var(
			$wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'created_at' )
		);
		$columns        = 'id, order_id, discount_amount, details' . ( $has_created_at ? ', created_at' : '' );

		// LIKE prefilter: promo_id always, rule_id only when the promo was
		// bridge-compiled into a rules row. Numeric values in JSON can appear
		// bare or quoted; the trailing , } ] variants avoid substring hits
		// (promo 5 vs 52). Rows are then verified by decoding the JSON below.
		$patterns = array();
		foreach ( array( 'promo_id' => $id, 'rule_id' => $rule_id ) as $key => $val ) {
			if ( $val <= 0 ) {
				continue;
			}
			$patterns[] = '%"' . $key . '":' . $val . ',%';
			$patterns[] = '%"' . $key . '":' . $val . '}%';
			$patterns[] = '%"' . $key . '":' . $val . ']%';
			$patterns[] = '%"' . $key . '":"' . $val . '"%';
		}

		$where = implode( ' OR ', array_fill( 0, count( $patterns ), 'details LIKE %s' ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT {$columns} FROM {$table} WHERE {$where}", $patterns ),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$discount_total = 0.0;
		$order_ids      = array();
		$matched        = array();

		foreach ( $rows as $row ) {
			$details = json_decode( isset( $row['details'] ) ? (string) $row['details'] : '', true );
			if ( ! $this->details_reference_promo( $details, $id, $rule_id ) ) {
				continue;
			}
			$matched[]       = $row;
			$discount_total += (float) $row['discount_amount'];
			$order_ids[ (int) $row['order_id'] ] = true;
		}
		$order_ids = array_keys( $order_ids );

		// Approximate assisted revenue: total of the orders that carried this
		// promo. Loaded via wc_get_order and capped so a very popular promo
		// cannot hydrate thousands of orders in one request.
		$assisted_revenue = 0.0;
		$order_dates      = array();
		if ( function_exists( 'wc_get_order' ) && ! empty( $order_ids ) ) {
			foreach ( array_slice( $order_ids, 0, 200 ) as $order_id ) {
				try {
					$order = wc_get_order( $order_id );
					if ( $order ) {
						$assisted_revenue += (float) $order->get_total();
						$created           = $order->get_date_created();
						if ( $created ) {
							$order_dates[ $order_id ] = $created->date( 'Y-m-d' );
						}
					}
				} catch ( \Throwable $e ) {
					// Skip unreadable orders; stats must never fatal.
					continue;
				}
			}
		}

		// Daily series (last 30 days): prefer the row's created_at when the
		// column exists, fall back to the order's creation date. Rows with no
		// resolvable date are simply excluded from the series (still counted
		// in the totals above). Empty array => "no daily data" for the UI.
		$by_day = array();
		foreach ( $matched as $row ) {
			$date = '';
			if ( $has_created_at && ! empty( $row['created_at'] ) ) {
				$date = substr( (string) $row['created_at'], 0, 10 );
			} elseif ( isset( $order_dates[ (int) $row['order_id'] ] ) ) {
				$date = $order_dates[ (int) $row['order_id'] ];
			}
			if ( '' === $date ) {
				continue;
			}
			if ( ! isset( $by_day[ $date ] ) ) {
				$by_day[ $date ] = array( 'uses' => 0, 'discount' => 0.0 );
			}
			$by_day[ $date ]['uses']++;
			$by_day[ $date ]['discount'] += (float) $row['discount_amount'];
		}

		$series = array();
		if ( ! empty( $by_day ) ) {
			// Zero-filled 30-day window ending today, so the sparkline has a
			// stable x-axis even with sparse data.
			$tz  = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
			$day = new \DateTimeImmutable( 'now', $tz );
			for ( $i = 29; $i >= 0; $i-- ) {
				$key      = $day->modify( "-{$i} days" )->format( 'Y-m-d' );
				$series[] = array(
					'date'     => $key,
					'uses'     => isset( $by_day[ $key ] ) ? (int) $by_day[ $key ]['uses'] : 0,
					'discount' => isset( $by_day[ $key ] ) ? round( $by_day[ $key ]['discount'], 2 ) : 0.0,
				);
			}
			// If every point in the window is zero (all activity older than 30
			// days), report no daily data instead of a flat line.
			$has_signal = false;
			foreach ( $series as $point ) {
				if ( $point['uses'] > 0 || $point['discount'] > 0 ) {
					$has_signal = true;
					break;
				}
			}
			if ( ! $has_signal ) {
				$series = array();
			}
		}

		return new \WP_REST_Response(
			array(
				'promoId'          => $id,
				'name'             => isset( $promo['name'] ) ? (string) $promo['name'] : '',
				'uses'             => isset( $promo['uses'] ) ? (int) $promo['uses'] : 0,
				'discountTotal'    => round( $discount_total, 2 ),
				'ordersCount'      => count( $order_ids ),
				'assistedRevenue'  => round( $assisted_revenue, 2 ),
				'assistedIsApprox' => true,
				'currencySymbol'   => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$',
				'byDay'            => $series,
				'hasDailyData'     => ! empty( $series ),
			),
			200
		);
	}

	/**
	 * Whether a decoded `details` JSON structure references the given promo.
	 *
	 * Accepts any nesting: an object with promo_id / rule_id at the top level,
	 * a list of entries each carrying those keys, or deeper envelopes. Today's
	 * writer stores a bare [] (no reference), which correctly returns false.
	 *
	 * @param mixed $details Decoded JSON (array) or null on decode failure.
	 * @param int   $promo_id Promo primary key.
	 * @param int   $rule_id  Bridge rule id (0 when the promo has none).
	 * @return bool
	 */
	private function details_reference_promo( $details, $promo_id, $rule_id ) {
		if ( ! is_array( $details ) || empty( $details ) ) {
			return false;
		}

		if ( isset( $details['promo_id'] ) && is_scalar( $details['promo_id'] ) && (int) $details['promo_id'] === $promo_id ) {
			return true;
		}
		if ( $rule_id > 0 && isset( $details['rule_id'] ) && is_scalar( $details['rule_id'] ) && (int) $details['rule_id'] === $rule_id ) {
			return true;
		}

		foreach ( $details as $value ) {
			if ( is_array( $value ) && $this->details_reference_promo( $value, $promo_id, $rule_id ) ) {
				return true;
			}
		}

		return false;
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
	// Conflict pre-check (advisory -- never blocks a save)
	// ------------------------------------------------------------------

	/**
	 * POST /drw/v1/promos/check-conflicts
	 *
	 * Soft, non-blocking pre-flight check for a promo still being drafted in
	 * the wizard (create OR edit -- pass the promo's own `id` in the payload
	 * while editing so it is excluded from the duplicate-code and overlap
	 * checks against itself). It re-runs a curated subset of the same rules
	 * validate_promo() enforces hard, downgraded to advisory items:
	 *
	 *   (a) overlap        - another ACTIVE promo with an overlapping product/
	 *                        category scope and overlapping date range.
	 *   (b) duplicate code - reuses find_duplicate_code(), the exact same
	 *                        lookup validate_promo() and check-code use.
	 *   (c) bad dates       - invalid Y-m-d format, or end date before start.
	 *   (d) value >= 100%   - a percent-type promo with an evident zero/negative
	 *                        margin.
	 *   (e) empty scope     - target is 'products'/'category' but no ids were
	 *                        selected, so the promo would discount nothing.
	 *
	 * This endpoint ALWAYS returns 200 with a `warnings` array -- it never
	 * returns a 4xx and never mutates any data. The only gate that actually
	 * blocks create_promo()/update_promo() from persisting bad data remains
	 * validate_promo(); this is purely an early-warning UI aid.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function check_conflicts( $request ) {
		$data = $this->get_request_data( $request );

		$exclude_id = ( isset( $data['id'] ) && is_numeric( $data['id'] ) ) ? (int) $data['id'] : null;

		$type  = isset( $data['type'] ) ? sanitize_text_field( $data['type'] ) : '';
		$value = isset( $data['value'] ) ? floatval( $data['value'] ) : 0;
		$code  = isset( $data['code'] ) ? strtoupper( sanitize_text_field( $data['code'] ) ) : '';
		$start = isset( $data['start'] ) ? sanitize_text_field( $data['start'] ) : '';
		$end   = isset( $data['end'] ) ? sanitize_text_field( $data['end'] ) : '';
		$scope = $this->sanitize_scope( isset( $data['scope'] ) ? $data['scope'] : '' );

		$warnings = array();

		// (b) Duplicate code -- same lookup validate_promo() uses to hard-fail,
		// here surfaced as a warning instead of aborting the request.
		if ( '' !== $code ) {
			$duplicate = $this->find_duplicate_code( $code, $exclude_id );
			if ( $duplicate ) {
				$warnings[] = array(
					'severity' => 'warning',
					'field'    => 'code',
					'message'  => $duplicate,
				);
			}
		}

		// (c) Incoherent dates -- format first, then the end-before-start
		// comparison validate_promo() rejects with 'invalid_date_range'.
		$start_valid = ( '' === $start || $this->is_valid_date( $start ) );
		$end_valid   = ( '' === $end || $this->is_valid_date( $end ) );

		if ( ! $start_valid ) {
			$warnings[] = array(
				'severity' => 'error',
				'field'    => 'start',
				'message'  => __( 'Start date must be in Y-m-d format.', 'discount-rules-woo' ),
			);
		}
		if ( ! $end_valid ) {
			$warnings[] = array(
				'severity' => 'error',
				'field'    => 'end',
				'message'  => __( 'End date must be in Y-m-d format.', 'discount-rules-woo' ),
			);
		}
		if ( $start_valid && $end_valid && '' !== $start && '' !== $end && $end < $start ) {
			$warnings[] = array(
				'severity' => 'error',
				'field'    => 'end',
				'message'  => __( 'End date must be on or after the start date.', 'discount-rules-woo' ),
			);
		}

		// (d) Percentage discount at or above 100% -- an evident zero/negative
		// margin. Above 100 is already a hard validate_promo() rejection, so
		// it is flagged as an error here too; exactly 100 is technically
		// allowed on save (gives the product away for free) but still worth
		// a strong warning.
		if ( 'percent' === $type && $value >= 100 ) {
			$warnings[] = array(
				'severity' => $value > 100 ? 'error' : 'warning',
				'field'    => 'value',
				'message'  => $value > 100
					? __( 'A percentage above 100% will be rejected when the promo is saved.', 'discount-rules-woo' )
					: __( 'A 100% discount gives the product away for free (zero margin).', 'discount-rules-woo' ),
			);
		}

		// (e) Empty scope -- a products/category target with no ids selected
		// discounts nothing, which is almost always a mistake mid-draft.
		if ( is_array( $scope ) && in_array( $scope['target'], array( 'products', 'category' ), true ) && empty( $scope['ids'] ) ) {
			$warnings[] = array(
				'severity' => 'warning',
				'field'    => 'scope',
				'message'  => __( 'No products or categories are selected -- this promo would not apply to anything.', 'discount-rules-woo' ),
			);
		}

		// (a) Overlap with another ACTIVE promo on the same scope and an
		// overlapping date range. Skipped while the dates themselves are
		// malformed (already flagged by (c) above).
		if ( $start_valid && $end_valid ) {
			foreach ( $this->find_scope_overlaps( $scope, $start, $end, $exclude_id ) as $other_label ) {
				$warnings[] = array(
					'severity' => 'warning',
					'field'    => 'scope',
					'message'  => sprintf(
						/* translators: %s: name/code of the conflicting promo */
						__( 'Overlaps with the active promo "%s" on the same products/categories and dates.', 'discount-rules-woo' ),
						$other_label
					),
				);
			}
		}

		return new \WP_REST_Response(
			array(
				'ok'       => ! $this->warnings_have_errors( $warnings ),
				'warnings' => $warnings,
			),
			200
		);
	}

	/**
	 * Whether any item in a check_conflicts() warnings list is severity 'error'.
	 *
	 * @param array $warnings List of { severity, field, message } items.
	 * @return bool
	 */
	private function warnings_have_errors( $warnings ) {
		foreach ( $warnings as $warning ) {
			if ( isset( $warning['severity'] ) && 'error' === $warning['severity'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Find active promos whose scope and date range overlap a draft's.
	 *
	 * Deliberately simple -- this is an advisory check for the wizard, not the
	 * discount engine's product resolver:
	 * - a sitewide ('all') scope on either side is treated as overlapping
	 *   everything;
	 * - 'products' vs 'products' and 'category' vs 'category'/'categories'
	 *   overlap when their id sets intersect;
	 * - mixed 'products' vs 'category' targets, and legacy free-form string
	 *   scopes, cannot be compared structurally and are skipped (no false
	 *   positives).
	 *
	 * @param array|string $scope      Sanitised draft scope ({target, ids} or legacy string).
	 * @param string       $start      Draft start date (Y-m-d or '').
	 * @param string       $end        Draft end date (Y-m-d or '').
	 * @param int|null     $exclude_id Promo id to exclude (the draft's own id, when editing).
	 * @return string[] Display labels (name, falling back to code, falling back to id) of conflicting promos.
	 */
	private function find_scope_overlaps( $scope, $start, $end, $exclude_id ) {
		if ( ! is_array( $scope ) ) {
			// Legacy free-form string scope can't be compared structurally.
			return array();
		}

		$draft_target = $scope['target'];
		$draft_ids    = ! empty( $scope['ids'] ) ? $scope['ids'] : array();

		$conflicts = array();

		foreach ( PromoModel::get_all_promos() as $row ) {
			if ( empty( $row['active'] ) ) {
				continue;
			}
			if ( null !== $exclude_id && (int) $row['id'] === $exclude_id ) {
				continue;
			}
			if ( ! $this->dates_overlap( $start, $end, $row['date_from'], $row['date_to'] ) ) {
				continue;
			}
			if ( ! $this->scopes_overlap( $draft_target, $draft_ids, $row['scope'] ) ) {
				continue;
			}

			if ( ! empty( $row['name'] ) ) {
				$conflicts[] = (string) $row['name'];
			} elseif ( ! empty( $row['code'] ) ) {
				$conflicts[] = (string) $row['code'];
			} else {
				$conflicts[] = '#' . (int) $row['id'];
			}
		}

		return $conflicts;
	}

	/**
	 * Whether a draft's { target, ids } scope structurally overlaps a stored
	 * promo's scope (engine-native { target, product_ids, category_ids } or
	 * the legacy { raw } string envelope).
	 *
	 * @param string $draft_target 'all' | 'products' | 'category'.
	 * @param int[]  $draft_ids    Draft product/category ids (ignored when target is 'all').
	 * @param mixed  $stored_scope Decoded `scope` column from a PromoModel row.
	 * @return bool
	 */
	private function scopes_overlap( $draft_target, array $draft_ids, $stored_scope ) {
		if ( ! is_array( $stored_scope ) || isset( $stored_scope['raw'] ) ) {
			// Legacy free-form scope can't be compared structurally.
			return false;
		}

		$stored_target = isset( $stored_scope['target'] ) ? (string) $stored_scope['target'] : 'all';

		// A sitewide scope on either side touches every product/category.
		if ( 'all' === $draft_target || 'all' === $stored_target ) {
			return true;
		}

		if ( 'products' === $draft_target ) {
			if ( 'products' !== $stored_target ) {
				return false;
			}
			$stored_ids = ( isset( $stored_scope['product_ids'] ) && is_array( $stored_scope['product_ids'] ) ) ? $stored_scope['product_ids'] : array();
			return count( array_intersect( $draft_ids, $stored_ids ) ) > 0;
		}

		if ( 'category' === $draft_target ) {
			if ( ! in_array( $stored_target, array( 'category', 'categories' ), true ) ) {
				return false;
			}
			$stored_ids = ( isset( $stored_scope['category_ids'] ) && is_array( $stored_scope['category_ids'] ) ) ? $stored_scope['category_ids'] : array();
			return count( array_intersect( $draft_ids, $stored_ids ) ) > 0;
		}

		return false;
	}

	/**
	 * Whether two date ranges overlap, treating an empty bound as open-ended
	 * (no start = -infinity, no end = +infinity). Accepts either Y-m-d
	 * strings or DATETIME strings (only the first 10 characters are used).
	 *
	 * @param string      $start_a Range A start (Y-m-d or '').
	 * @param string      $end_a   Range A end (Y-m-d or '').
	 * @param string|null $start_b Range B start (Y-m-d, DATETIME, or empty/null).
	 * @param string|null $end_b   Range B end (Y-m-d, DATETIME, or empty/null).
	 * @return bool
	 */
	private function dates_overlap( $start_a, $end_a, $start_b, $end_b ) {
		$start_b = ! empty( $start_b ) ? substr( (string) $start_b, 0, 10 ) : '';
		$end_b   = ! empty( $end_b ) ? substr( (string) $end_b, 0, 10 ) : '';

		$a_starts_on_or_before_b_ends = ( '' === $start_a || '' === $end_b || $start_a <= $end_b );
		$b_starts_on_or_before_a_ends = ( '' === $start_b || '' === $end_a || $start_b <= $end_a );

		return $a_starts_on_or_before_b_ends && $b_starts_on_or_before_a_ends;
	}

	// ------------------------------------------------------------------
	// Dry-run price preview (never persists anything)
	// ------------------------------------------------------------------

	/**
	 * POST /drw/v1/promos/preview
	 *
	 * Dry-run price calculator for the wizard's live preview panel. It receives
	 * an UNSAVED promo draft (the same camelCase payload create/update accept)
	 * plus a sample `product_id`, and returns the before/after price for that
	 * product computed with the EXACT same code the discount engine runs in
	 * production — WITHOUT writing anything:
	 *
	 *   1. The draft is normalised into the snake_case "formatted promo row"
	 *      shape via to_columns() (a pure mapper, no DB), so it is byte-for-byte
	 *      the input PromoBridgeController::compile() would receive.
	 *   2. The engine-visible payload is produced by the SAME builders compile()
	 *      uses — PromoBridgeController::build_rule_payload() (Vía B) or
	 *      build_coupon_data() (Vía A) — but the wp_drw_rules row / WC_Coupon is
	 *      NEVER inserted. Those builders are pure; only compile() persists.
	 *   3. The resulting adjustment is applied to the real WC_Product:
	 *        - Vía B bogo / bundle_set / free_shipping run through the real
	 *          Adjustments\Bogo / BundleSet / FreeShipping engines on an
	 *          in-memory single-item stand-in cart (nothing touches WC()->cart).
	 *        - Vía B percentage / fixed / bulk reuse RulesEngine's own catalog
	 *          maths, gated by the real RulesEngine::is_product_targeted_by_rule()
	 *          scope check — no standalone Percentage/Fixed class exists; the
	 *          engine computes these inline in calculate_catalog_discount().
	 *        - Vía A (needs_code) is simulated with the simple discount_type
	 *          maths (percent / fixed_cart), never instantiating a WC_Coupon.
	 *
	 * Strictly read-only: no PromoModel writes, no sync_bridge(), no $wpdb
	 * INSERT/UPDATE. This method and its helpers only ever read.
	 *
	 * Payload: the promo draft fields at the top level (or nested under `promo`)
	 * plus `product_id` (alias `productId`).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response { priceBefore, priceAfter, productName, productImage, freeShipping }
	 */
	public function preview_promo( $request ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'WooCommerce is not available.', 'discount-rules-woo' ) ),
				503
			);
		}

		$data = $this->get_request_data( $request );

		$product_id = isset( $data['product_id'] ) ? absint( $data['product_id'] ) : 0;
		if ( $product_id <= 0 && isset( $data['productId'] ) ) {
			$product_id = absint( $data['productId'] );
		}
		if ( $product_id <= 0 ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'A sample product_id is required.', 'discount-rules-woo' ) ),
				400
			);
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Sample product not found.', 'discount-rules-woo' ) ),
				404
			);
		}

		// The draft may arrive nested under `promo` or spread at the top level.
		$promo = ( isset( $data['promo'] ) && is_array( $data['promo'] ) ) ? $data['promo'] : $data;

		$type = isset( $promo['type'] ) ? sanitize_text_field( $promo['type'] ) : '';
		if ( ! PromoTypeRegistry::exists( $type ) ) {
			return new \WP_REST_Response(
				array(
					/* translators: %s: comma-separated list of valid types */
					'message' => sprintf( __( 'Invalid type. Allowed: %s', 'discount-rules-woo' ), implode( ', ', PromoTypeRegistry::ids() ) ),
				),
				400
			);
		}

		// Normalise the draft into the exact column/row shape compile() consumes.
		// to_columns() is a pure mapper (no DB), so nothing is persisted here.
		$columns       = $this->to_columns( $this->preview_draft( $promo, $type ) );
		$columns['id'] = 0; // Builders read $promo['id'] only for coupon meta.

		// Engine base price: WooCommerce discounts are computed off the regular
		// price (see RulesEngine::calculate_catalog_discount); fall back to the
		// active price for products without one (e.g. variable/grouped).
		$price_before = (float) $product->get_regular_price();
		if ( $price_before <= 0 ) {
			$price_before = (float) $product->get_price();
		}

		$result = PromoTypeRegistry::needs_code( $type )
			? $this->preview_via_coupon( $columns, $price_before )          // Vía A
			: $this->preview_via_rule( $columns, $product, $price_before ); // Vía B

		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;

		return new \WP_REST_Response(
			array(
				'priceBefore'  => round( $price_before, $decimals ),
				'priceAfter'   => round( max( 0.0, $result['after'] ), $decimals ),
				'productName'  => $product->get_name(),
				'productImage' => $this->preview_product_image( $product ),
				'freeShipping' => (bool) $result['free_shipping'],
			),
			200
		);
	}

	/**
	 * Build a full camelCase draft (every key to_columns() reads) from a partial
	 * promo payload, applying the same sanitisers validate_promo() uses. Pure —
	 * no DB, no code-uniqueness lookups: a preview must render even for a draft a
	 * real save would reject (e.g. a duplicate code).
	 *
	 * @param array  $promo Raw promo payload (camelCase, possibly partial).
	 * @param string $type  Already-validated type id.
	 * @return array
	 */
	private function preview_draft( $promo, $type ) {
		return array(
			'name'        => isset( $promo['name'] ) ? sanitize_text_field( $promo['name'] ) : '',
			'code'        => isset( $promo['code'] ) ? strtoupper( sanitize_text_field( $promo['code'] ) ) : '',
			'type'        => $type,
			'value'       => isset( $promo['value'] ) ? floatval( $promo['value'] ) : 0,
			'scope'       => $this->sanitize_scope( isset( $promo['scope'] ) ? $promo['scope'] : '' ),
			'minAmount'   => isset( $promo['minAmount'] ) ? floatval( $promo['minAmount'] ) : 0,
			'limitGlobal' => isset( $promo['limitGlobal'] ) ? absint( $promo['limitGlobal'] ) : 0,
			'limitUser'   => isset( $promo['limitUser'] ) ? absint( $promo['limitUser'] ) : 0,
			'start'       => isset( $promo['start'] ) ? sanitize_text_field( $promo['start'] ) : '',
			'end'         => isset( $promo['end'] ) ? sanitize_text_field( $promo['end'] ) : '',
			'active'      => isset( $promo['active'] ) ? (bool) $promo['active'] : true,
			'home'        => isset( $promo['home'] ) ? (bool) $promo['home'] : false,
			'cartMessage' => isset( $promo['cartMessage'] ) ? sanitize_text_field( $promo['cartMessage'] ) : '',
			'giftText'    => isset( $promo['giftText'] ) ? sanitize_text_field( $promo['giftText'] ) : '',
		);
	}

	/**
	 * Vía A simulation: mirror what a native WC_Coupon would do to a single
	 * product's price using the SAME field mapping compile() would persist
	 * (PromoBridgeController::build_coupon_data, a pure builder), WITHOUT creating
	 * a coupon. Only the two discount_type shapes that builder emits are honoured
	 * (percent / fixed_cart), applied directly to the product price.
	 *
	 * @param array $columns      Formatted promo row.
	 * @param float $price_before Product base price.
	 * @return array { after: float, free_shipping: bool }
	 */
	private function preview_via_coupon( $columns, $price_before ) {
		$bridge = new PromoBridgeController();
		$coupon = $bridge->build_coupon_data( $columns );

		$after = $price_before;
		if ( 'percent' === $coupon['discount_type'] ) {
			$after = $price_before - ( $price_before * ( (float) $coupon['amount'] / 100 ) );
		} elseif ( 'fixed_cart' === $coupon['discount_type'] ) {
			$after = max( 0.0, $price_before - (float) $coupon['amount'] );
		}

		return array(
			'after'         => $after,
			'free_shipping' => ! empty( $coupon['free_shipping'] ),
		);
	}

	/**
	 * Vía B calculation: build the engine-visible rule payload with the SAME
	 * builder compile() uses (PromoBridgeController::build_rule_payload, pure)
	 * WITHOUT inserting the wp_drw_rules row, then apply its adjustment to the
	 * real WC_Product with the engine's own code:
	 *
	 *   - bogo / bundle_set / free_shipping → the real Adjustments\Bogo /
	 *     BundleSet / FreeShipping classes on an in-memory single-item cart.
	 *   - percentage / fixed / bulk → RulesEngine's catalog maths (there is no
	 *     dedicated Adjustment class for these; the engine inlines them in
	 *     calculate_catalog_discount()), gated by the real
	 *     RulesEngine::is_product_targeted_by_rule() scope check.
	 *
	 * @param array       $columns      Formatted promo row.
	 * @param \WC_Product $product      Sample product.
	 * @param float       $price_before Product base price.
	 * @return array { after: float, free_shipping: bool }
	 */
	private function preview_via_rule( $columns, $product, $price_before ) {
		$bridge  = new PromoBridgeController();
		$payload = $bridge->build_rule_payload( $columns );

		$adjustments = isset( $payload['adjustments'] ) ? (array) $payload['adjustments'] : array();
		$type        = isset( $adjustments['type'] ) ? (string) $adjustments['type'] : '';

		// Rule array in the exact shape RulesEngine::is_product_targeted_by_rule() reads.
		$rule = array(
			'apply_to'    => isset( $payload['apply_to'] ) ? $payload['apply_to'] : 'all_products',
			'filters'     => isset( $payload['filters'] ) ? $payload['filters'] : array(),
			'adjustments' => $adjustments,
			'conditions'  => isset( $payload['conditions'] ) ? $payload['conditions'] : array(),
		);

		$engine        = RulesEngine::instance();
		$after         = $price_before;
		$free_shipping = false;

		switch ( $type ) {
			case 'percentage':
				if ( $engine->is_product_targeted_by_rule( $rule, $product ) ) {
					$after = $price_before - ( $price_before * ( (float) $adjustments['value'] / 100 ) );
				}
				break;

			case 'fixed':
				if ( $engine->is_product_targeted_by_rule( $rule, $product ) ) {
					$after = max( 0.0, $price_before - (float) $adjustments['value'] );
				}
				break;

			case 'bulk':
				// Mirror RulesEngine::calculate_catalog_discount()'s tier match at qty 1.
				if ( $engine->is_product_targeted_by_rule( $rule, $product ) ) {
					$tiers = ! empty( $adjustments['tiers'] ) ? (array) $adjustments['tiers'] : array();
					foreach ( $tiers as $tier ) {
						$min_qty = isset( $tier['min'] ) ? (int) $tier['min'] : 0;
						$max_qty = ( isset( $tier['max'] ) && '' !== $tier['max'] ) ? (int) $tier['max'] : PHP_INT_MAX;
						if ( 1 >= $min_qty && 1 <= $max_qty ) {
							$tier_type  = ! empty( $tier['type'] ) ? $tier['type'] : 'percentage';
							$tier_value = (float) ( ! empty( $tier['value'] ) ? $tier['value'] : 0 );
							if ( 'percentage' === $tier_type ) {
								$after = $price_before - ( $price_before * ( $tier_value / 100 ) );
							} elseif ( 'fixed' === $tier_type ) {
								$after = max( 0.0, $price_before - $tier_value );
							}
							break;
						}
					}
				}
				break;

			case 'bogo':
				// Exercise the real Bogo engine with enough units to trigger one
				// buy+get group, then report the resulting per-unit average price.
				$qty  = max( 1, (int) ( isset( $adjustments['buy_qty'] ) ? $adjustments['buy_qty'] : 1 ) + (int) ( isset( $adjustments['get_qty'] ) ? $adjustments['get_qty'] : 1 ) );
				$cart = $this->preview_cart( $product, $qty );
				$res  = ( new Bogo() )->calculate( $adjustments, $cart );
				if ( isset( $res['preview'] ) ) {
					$after = (float) $res['preview'];
				}
				break;

			case 'bundle_set':
				// A single sample product can only form a bundle when the bundle
				// is defined entirely on that product; otherwise price is unchanged.
				$cart = $this->preview_cart( $product, 1 );
				$res  = ( new BundleSet() )->calculate( $adjustments, $cart );
				if ( ! empty( $res['applied'] ) && isset( $res['items']['preview'] ) ) {
					$after = (float) $res['items']['preview'];
				}
				break;

			case 'free_shipping':
				$cart          = $this->preview_cart( $product, 1 );
				$free_shipping = (bool) ( new FreeShipping() )->is_free_shipping_unlocked( $adjustments, $cart );
				break;
		}

		return array(
			'after'         => $after,
			'free_shipping' => $free_shipping,
		);
	}

	/**
	 * Build a minimal, in-memory stand-in cart holding a single line of the
	 * sample product. It implements ONLY the read-only surface the Adjustments
	 * engines touch (is_empty / get_cart / get_subtotal /
	 * get_cart_contents_count / get_applied_coupons). Nothing is added to the
	 * real WC()->cart or persisted anywhere — it exists purely for this
	 * calculation and is discarded when the request ends.
	 *
	 * @param \WC_Product $product Sample product.
	 * @param int         $qty     Line quantity.
	 * @return object Duck-typed WC_Cart stand-in.
	 */
	private function preview_cart( $product, $qty ) {
		$qty   = max( 1, (int) $qty );
		$items = array(
			'preview' => array(
				'data'         => $product,
				'quantity'     => $qty,
				'product_id'   => $product->get_id(),
				'variation_id' => 0,
			),
		);
		$subtotal = (float) $product->get_regular_price() * $qty;

		return new class( $items, $subtotal, $qty ) {
			/** @var array */
			private $items;
			/** @var float */
			private $subtotal;
			/** @var int */
			private $count;

			public function __construct( $items, $subtotal, $count ) {
				$this->items    = $items;
				$this->subtotal = (float) $subtotal;
				$this->count    = (int) $count;
			}

			public function is_empty() {
				return empty( $this->items );
			}

			public function get_cart() {
				return $this->items;
			}

			public function get_subtotal() {
				return $this->subtotal;
			}

			public function get_cart_contents_count() {
				return $this->count;
			}

			public function get_applied_coupons() {
				return array();
			}
		};
	}

	/**
	 * Resolve a thumbnail URL for the sample product, falling back to the
	 * WooCommerce placeholder image.
	 *
	 * @param \WC_Product $product Sample product.
	 * @return string
	 */
	private function preview_product_image( $product ) {
		$image_id = (int) $product->get_image_id();
		$url      = '';
		if ( $image_id > 0 ) {
			$url = wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );
		}
		if ( ! $url && function_exists( 'wc_placeholder_img_src' ) ) {
			$url = wc_placeholder_img_src( 'woocommerce_thumbnail' );
		}
		return $url ? $url : '';
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

	// ------------------------------------------------------------------
	// Legacy migration (one-shot, admin-triggered)
	// ------------------------------------------------------------------

	/**
	 * GET /drw/v1/promos/legacy-migration
	 *
	 * Read-only status check so the admin UI can decide whether to show the
	 * "migrate now" banner at all, and to render an accurate count instead of
	 * a blind "click to migrate" button.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_legacy_migration_status() {
		$decoded      = json_decode( get_option( PromoMigrationController::LEGACY_KEY, '[]' ), true );
		$legacy_count = is_array( $decoded ) ? count( $decoded ) : 0;

		$migrated_ids    = get_option( PromoMigrationController::MIGRATED_IDS_KEY, array() );
		$migrated_count  = is_array( $migrated_ids ) ? count( $migrated_ids ) : 0;
		$backup_exists   = null !== get_option( PromoMigrationController::BACKUP_KEY, null );

		return new \WP_REST_Response(
			array(
				'legacyCount'     => $legacy_count,
				'migratedCount'   => $migrated_count,
				'backupExists'    => $backup_exists,
				// False once every legacy entry has already been migrated (or
				// there was never any legacy data to begin with) -- lets the
				// UI hide the banner instead of offering a no-op button.
				'needsMigration'  => $legacy_count > 0 && $migrated_count < $legacy_count,
			),
			200
		);
	}

	/**
	 * POST /drw/v1/promos/legacy-migration
	 *
	 * Runs PromoMigrationController::migrate_legacy_promos(). Safe to call
	 * more than once (accidental double-click included): already-migrated
	 * legacy entries are tracked and skipped, so a repeat call can only ever
	 * finish an incomplete migration, never duplicate a row. See
	 * PromoMigrationController for the full contract and status values.
	 *
	 * @return \WP_REST_Response
	 */
	public function run_legacy_migration() {
		$result = PromoMigrationController::migrate_legacy_promos();

		$status_code = 'incomplete' === $result['status'] ? 207 : 200; // 207: partial success, safe to retry.

		return new \WP_REST_Response( $result, $status_code );
	}

}
