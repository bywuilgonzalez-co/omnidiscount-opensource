<p align="center">
  <img src="assets/images/logo.png" alt="Omni Discount — Dynamic Pricing & Discount Rules for WooCommerce" width="280">
</p>

# OmniDiscount — Dynamic Pricing & Discount Rules for WooCommerce

![Version](https://img.shields.io/badge/version-1.6.0-4338ca?style=flat-square)
![License](https://img.shields.io/badge/license-GPLv3-blue?style=flat-square)
![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-21759B?style=flat-square&logo=wordpress&logoColor=white)
![WooCommerce](https://img.shields.io/badge/WooCommerce-6.0%2B-96588A?style=flat-square&logo=woocommerce&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?style=flat-square&logo=php&logoColor=white)
![HPOS](https://img.shields.io/badge/HPOS-compatible-2e7d32?style=flat-square)

**The open-source edition of OmniDiscount** — a complete dynamic pricing, discount rules, and promotional-campaign engine for WooCommerce. Two pricing systems, a real condition engine, a welcome-coupon email-capture popup with anti-fraud protection, and a built-in analytics dashboard — all in one clean, self-contained plugin.

---

## Why OmniDiscount

Most discount plugins force a choice between a rigid coupon system and a bloated rules engine bolted on top of it. OmniDiscount treats both as first-class, and runs them through the **same pricing pipeline** so behavior stays predictable no matter which one a merchant reaches for:

- **Reglas** — manually configured, condition-gated pricing rules (percentage, fixed, tiered/bulk, BOGO, bundles, free shipping).
- **Cupones y Promociones** — a 15-type campaign catalogue (launch pricing, 2x1/3x2, welcome coupons, flash sales, gift-with-purchase, cashback, and more) that compiles into either a real `WC_Coupon` or an automatic rule, so it reuses the exact same calculation engine instead of a parallel one.

On top of that: a **welcome-popup** that captures an email and mints a unique, single-use coupon per visitor (with optional double opt-in and fully customizable HTML emails), **first-purchase identity verification** to stop the same person farming multiple "new customer" discounts, and a real-time **analytics dashboard**.

---

## Table of Contents

- [Features](#features)
- [Installation](#installation)
- [Usage](#usage)
- [Admin Interface](#admin-interface)
- [REST API](#rest-api)
- [Project Structure](#project-structure)
- [Contributing](#contributing)
- [Changelog](#changelog)
- [License](#license)

---

## Features

### Reglas — condition-gated pricing rules

| Type | Description |
|------|-------------|
| **Percentage / Fixed** | `%` or flat-amount discount, per-item or cart-wide |
| **Bulk / Tiered Pricing** | Quantity tiers (e.g. Buy 1–5 get 5%, Buy 6+ get 10%) |
| **BOGO** | Buy X, Get Y free, at a %/fixed discount, or the cheapest item free — same product or a different one |
| **Bundle Set** | Fixed price for a defined set of products, with proportional discount allocation |
| **Free Shipping** | Unlocked by subtotal, quantity, and/or product/category scope |

Every rule supports **exclusivity** (stop other rules from stacking), **coupon-stacking control**, **exclude-sale-items**, product/category **targeting with exclusions**, scheduling (date ranges, usage limits), and a configurable **compounding strategy** when multiple rules match.

### Cupones y Promociones — 15-type campaign catalogue

Percentage, fixed, launch pricing, 2x1, 3x2, second-unit discount, amount-based tiers, bundles, free-shipping threshold, code-gated free shipping, welcome coupon, gift-with-purchase, cashback, flash sale, and data-capture. Each promo compiles automatically into a real WooCommerce coupon or an automatic rule — never a separate pricing code path.

### Condition Engine

17 condition types, all combinable on a single rule: cart subtotal, item count/quantity/weight, user role, user email (with wildcard domains), specific users, logged-in status, shipping/billing location (with wildcards), product/category presence, product combinations, on-sale detection, applied coupon (with a schedule window), order date/time (including recurring daily windows and allowed weekdays), and **purchase history** — total spent, order count, first-order detection, previously bought products/categories.

### Welcome Popup + Unique Coupons

An on-site email-capture popup that mints a **unique, single-use coupon per visitor** — never a shared code. Supports instant reveal or an optional double opt-in (confirmation email), with a fully customizable HTML email editor (subject/heading/body or raw HTML with template variables) and a live preview. Hardened against abuse with a honeypot, dwell-time verification, per-IP/per-email/global rate limits, and atomic claim-then-mint to prevent race conditions.

### First-Purchase Identity Verification

An optional checkout field that cross-checks a welcome coupon's usage against **both** billing email and identity document across prior orders — closing the loop on customers farming multiple "first purchase" discounts with different accounts. Fails safe, fails generic (never reveals which field matched), and closes both the request-window and concurrent-request race conditions.

### Analytics

A built-in dashboard: total discount amount, orders-with-discount count, free-shipping-order count, average discount, a day/week time series, and top rules/promos by both dollar amount and redemption count.

### Storefront

- Strikeout pricing (`~~$20.00~~ $16.00`) in shop loops and product pages
- "You Saved: $X" summary at cart and order totals
- Sale badges with percentage on product cards
- Cart progress bar toward a discount/free-shipping threshold
- Volume-pricing tier display on product pages

### Compatibility

- WooCommerce **HPOS** (High-Performance Order Storage) — fully declared compatible
- WooCommerce Blocks (Cart/Checkout) via the Store API — not just classic checkout
- Multisite-safe

---

## Installation

### From WordPress Admin

1. Download the latest source as a ZIP from this repository (**Code → Download ZIP**, or a tagged [release](../../releases) if one is published).
2. In your WordPress admin go to **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP and click **Install Now**, then **Activate**.

### From Source

```bash
git clone https://github.com/bywuilgonzalez-co/omnidiscount-opensource.git
cp -r omnidiscount-opensource /your-site/wp-content/plugins/discount-rules-woo
```

Activate from **Plugins → Installed Plugins** in your WordPress admin.

> This is the open-source edition: no build step required, the compiled admin app (`assets/js/admin-app.js`) is committed directly. It does not include the managed automatic-update channel — check back here for new tags, or reach out via [bywuilgonzalez.com](https://bywuilgonzalez.com) if you'd like updates delivered automatically to your site.

---

## Usage

### Creating a Rule

1. Go to the **OmniDiscount** menu in your WordPress admin.
2. Click **+ Crear regla**, or start from the one-click template gallery.
3. Set a **title**, **priority**, and **discount type**.
4. Under **Apply To**, choose all products, specific products, or specific categories — use **Exclusions** to carve out exceptions.
5. Add **Conditions** as needed.
6. Optionally schedule with a **Date From / Date To** window.
7. Save.

### Shortcodes

```
[drw_sale_items_list limit="12" columns="4" category="combos"]
[drw_featured_promos limit="6" columns="3"]
[drw_cart_progress goal="100000" label="Te faltan {remaining} para envío gratis"]
[drw_volume_pricing product_id="123"]
```

| Shortcode | Purpose |
|---|---|
| `[drw_sale_items_list]` (aliases: `[awdr_sale_items_list]`, `[on_sale]`) | Grid of currently discounted products |
| `[drw_featured_promos]` | Showcase of active automatic promotions |
| `[drw_cart_progress]` | Progress bar toward a discount/free-shipping threshold |
| `[drw_volume_pricing]` | Bulk/tiered pricing table for a product |

---

## Admin Interface

A single top-level **OmniDiscount** menu, built with `@wordpress/components` for a native WordPress-admin feel:

- **Reglas** — rules dashboard, template gallery, rule editor
- **Cupones y Promociones** — the 15-type promo catalogue
- **Análisis** — discount performance dashboard
- **Configuración** — general behavior, enabled types, appearance, popup settings
- **Registros del popup** — welcome-popup submissions, with CSV export
- **Importar / Exportar** — rules JSON import/export

---

## REST API

All endpoints live under the `drw/v1` namespace and are protected by the `manage_woocommerce` capability, authenticated via the standard WordPress REST nonce (`X-WP-Nonce`).

| Resource | Endpoints |
|---|---|
| Rules | `GET/POST /rules`, `GET/DELETE /rules/{id}`, `POST /rules/{id}/sandbox`, `GET /products` |
| Promos | `GET/POST /promos`, `PUT/DELETE /promos/{id}`, `POST /promos/{id}/toggle`, `GET /promos/{id}/stats`, `POST /promos/{id}/sandbox`, `GET /promos/types`, `GET /promos/check-code`, `POST /promos/check-conflicts`, `POST /promos/preview`, `GET/POST /promos/legacy-migration` |
| Settings | `GET/POST /settings`, `POST /settings/reset`, `GET /settings/types`, `GET /settings/conditions`, `GET /settings/themes` |
| Analytics | `GET /analytics` |
| Import/Export | `GET /export`, `POST /import` |
| Popup | `GET /popup/submissions`, `POST /popup/preview-email` |
| Diagnostics | `GET /diagnostics` — flags other active pricing/coupon plugins that may conflict |

The public-facing popup submission itself is served via `admin-ajax.php` (`drw_popup_submit`/`drw_popup_confirm`), matching WordPress's own pattern for anonymous-traffic endpoints.

---

## Project Structure

```
discount-rules-woo/
├── discount-rules-woo.php          # Plugin bootstrap & constants
├── src/
│   ├── class-router.php            # Hook registration orchestrator, migrations, cron
│   ├── Adjustments/                # One class per Rules discount type (BOGO, Bundle, FreeShipping)
│   ├── Conditions/                 # One class per condition type (17 total)
│   ├── Controllers/
│   │   ├── RulesEngine.php         # Core discount calculation orchestrator
│   │   ├── PromosController.php    # Promo catalogue CRUD/REST
│   │   ├── PromoBridgeController.php   # Compiles promos into coupons/rules
│   │   ├── PopupController.php     # Welcome-popup capture + emails
│   │   ├── PopupCouponBridge.php   # Unique per-visitor coupon minting
│   │   ├── CartController.php      # Cart pricing, order metadata, identity verification
│   │   ├── CatalogController.php   # Shop/product-page price display
│   │   ├── AnalyticsController.php # Analytics REST + admin screen
│   │   ├── StoreApiController.php  # WooCommerce Blocks (Store API) integration
│   │   ├── ShortcodeController.php # Storefront shortcodes
│   │   └── Updater.php             # Self-update integration
│   └── Models/
│       ├── Database.php            # Table creation & migrations
│       ├── RuleModel.php           # Rules CRUD + usage reservation
│       ├── PromoModel.php          # Promos CRUD
│       ├── PromoTypeRegistry.php   # Single source of truth for promo types
│       └── class-settingsmodel.php # Centralized settings store
├── assets/
│   ├── css/                        # Admin + storefront styles
│   └── js/admin-app.js             # Compiled React admin app
└── tests/                          # Standalone integration test scripts
```

---

## Contributing

Contributions are welcome. Please open an issue first to discuss significant changes — see [CONTRIBUTING.md](.github/CONTRIBUTING.md).

1. Fork the repository.
2. Create a feature branch: `git checkout -b feature/my-feature`.
3. Commit your changes.
4. Push and open a Pull Request targeting `main`.

---

## Changelog

### 1.6.0
- Added a welcome-popup email-capture flow with unique, single-use coupons per visitor and optional double opt-in
- Added first-purchase identity verification to prevent welcome-coupon abuse across multiple accounts
- Added fully customizable HTML confirmation and code-reveal emails with a live preview and template variables
- Fixed a pricing bug where certain BOGO/gift promotions could collapse cart lines to $0
- Added promo exclusivity, atomic usage reservation, and granular accumulation controls
- Redesigned the Analytics panel (backend aggregation + frontend)

### 1.5.0
- Rebranded to **OmniDiscount** with a dedicated top-level admin menu
- Added the Promos engine (coupons & promotions) with automatic migration from legacy storage
- Added the Analytics page
- Added rules import/export

### 1.2.1
- Fixed scheduled rules and dynamic sale-shortcode discovery
- Added advanced targeting exclusions and coupon schedule windows
- Added the sale-items shortcode with percentage badges
- Added scalable async product search in admin

### 1.0.0
- Initial release

---

## License

[GPLv3 or later](https://www.gnu.org/licenses/gpl-3.0.html) — free to use, modify, and distribute.

---

*Developed by [Bywuilgonzalez.com](https://bywuilgonzalez.com)*
