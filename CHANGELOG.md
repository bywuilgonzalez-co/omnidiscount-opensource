# Changelog

## [1.3.0] – 2026-06-14

### Fixed
- **Catalog display bug**: Crossed-out/strikethrough prices now display correctly. The recursion guard (`is_calculating`) was being reset before reading the current price, causing WooCommerce to return the already-discounted price and making the comparison always false.
- **Category discounts not applying**: Rules targeting specific categories now correctly match variable product variations. Previously, `wc_get_product_term_ids()` was called with the variation ID (which has no category assignments), never returning results. Fixed by falling back to the parent product ID when the variation returns no categories.
- **Category discounts on products with attributes**: Same root-cause fix applied in `RulesEngine::is_product_targeted_by_rule()` (both excluded categories and targeted categories) and `CartController::is_product_in_list()`.
- **SQL anti-pattern**: Replaced direct table-name string interpolation with `esc_sql()` and `$wpdb->prepare()` in `Database.php`.

### Added
- **Coupon stacking control**: New global setting (WooCommerce → Discount Rules Settings) to disable all discount rules when a coupon code is applied. Also supported per-rule via the `no_coupon_stacking` field in the REST API.
- **Block Cart/Checkout support**: New `StoreApiController` integrates with the WooCommerce Store API so discounts apply in the Block Cart and Block Checkout (WC 8.4+).
- **Discount analytics**: New `AnalyticsController` with REST endpoint `GET /drw/v1/analytics?days=N` and a dashboard page (WooCommerce → Discount Analytics) showing total discounts, order counts, and averages.
- **Import/Export**: New `ImportExportController` with JSON export/import via UI (WooCommerce → Import/Export Rules) and REST endpoints `GET /drw/v1/export` and `POST /drw/v1/import`.
- **Cart progress bar**: New `[drw_cart_progress]` shortcode shows a progress bar toward free shipping or a discount threshold.
- **Volume pricing table**: New `[drw_volume_pricing]` shortcode renders a tiered pricing table on product pages.
- **Priority index**: Added `priority` index to `wp_drw_rules` table for faster rule ordering queries.
- **Analytics table timestamp**: Added `created_at` column to `wp_drw_order_discounts` table (auto-added to existing installs via dbDelta).

## [1.2.1] – Previous release

See git history for details.
