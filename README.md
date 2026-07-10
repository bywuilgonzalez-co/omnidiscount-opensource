# OmniDiscount — Dynamic Pricing & Discount Rules for WooCommerce

A free, open-source plugin for WooCommerce that gives store owners a complete engine for dynamic pricing, discount rules, and promotional logic — without recurring fees.

**Version:** 1.5.0 · **License:** GPLv3 · **Requires:** WordPress 5.6+, WooCommerce 6.0+, PHP 7.4+

---

## Features

### Discount Types
| Type | Description |
|------|-------------|
| **Percentage** | Apply a `%` discount storewide, by category, or per product |
| **Fixed Amount** | Subtract a flat amount from the price |
| **Bulk / Tiered Pricing** | Different discounts per quantity range (e.g. Buy 1–5 get 5%, Buy 6+ get 10%) |
| **BOGO** | Buy X, Get Y free or at a reduced price |
| **Bundle Set** | Fixed price for a defined set of products |
| **Free Shipping** | Override shipping cost to zero when conditions are met |

### Condition Engine

Rules only apply when all configured conditions are satisfied:

- Cart subtotal above/below a threshold
- Cart item quantity or line item count
- Specific products or categories in cart
- User role (including **Guest** check)
- Specific user email list or wildcard domain (`*@company.com`)
- Billing city, state, country, or postcode (with wildcards)
- Shipping location
- Purchase history: total spent, order count, first-order detection, previously bought products/categories
- Cart coupon applied (with optional schedule window)
- Order date range

### Target & Exclusion System

- Target **all products**, **specific products**, or **specific categories**
- Exclude individual products or categories from within a targeted group
- Rules stack with configurable **priority** (lower number = higher priority)
- Mark a rule as **exclusive** to prevent other rules from stacking

### Scheduled Rules

Every rule supports optional date/time windows:

- **Date From / Date To** — activate a rule only within a calendar range
- **Usage Limit** — cap how many times a rule fires globally
- **Cart Coupon schedule** — restrict coupon discounts to time-of-day windows (e.g. 07:00–10:00) or a duration in minutes (e.g. 30-minute flash sale)

### Storefront

- Strikeout prices (`~~$20.00~~ $16.00`) in shop loops and product pages
- Savings summary at cart and order totals ("You Saved: $4.00")
- Sale badge with percentage on product cards
- **Sale Items Shortcode** — renders a grid of currently discounted products

### Admin Interface

- Modern React UI under its own top-level **OmniDiscount** admin menu
- Async product and category search (no page reload required)
- Real-time rule preview
- Powered by `@wordpress/components` — no external UI dependencies

### Compatibility

- WooCommerce **HPOS** (High-Performance Order Storage) — fully declared compatible
- Standard WooCommerce order storage
- Multisite-safe (uses `$wpdb->prefix`)

---

## Installation

### From GitHub (Manual)

1. Download the latest release ZIP from the [Releases page](https://github.com/bywuilgonzalez-co/discount-rules-woo/releases).
2. In your WordPress admin go to **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP and click **Install Now**, then **Activate**.

### From Source

```bash
git clone https://github.com/bywuilgonzalez-co/discount-rules-woo.git
cp -r discount-rules-woo /your-site/wp-content/plugins/
```

Activate from **Plugins → Installed Plugins** in your WordPress admin.

### Auto-Updates

The plugin includes a built-in GitHub-based updater. Once installed, it checks for new releases automatically and shows them in the standard WordPress **Dashboard → Updates** screen — just like a plugin from wordpress.org.

---

## Usage

### Creating a Rule

1. Go to the **OmniDiscount** menu in your WordPress admin.
2. Click **+ Crear regla**.
3. Set a **title**, **priority**, and the **discount type** (Percentage, Fixed, Bulk, BOGO, Bundle, Free Shipping).
4. Under **Apply To**, choose all products, specific products, or specific categories. Use **Exclusions** to carve out exceptions.
5. Add **Conditions** as needed (user role, cart subtotal, etc.).
6. Optionally set a **Date From / Date To** to schedule the rule.
7. Save.

### Sale Items Shortcode

Embed a grid of discounted products on any page or post:

```
[drw_sale_items_list limit="12" columns="4"]
```

Filter by category slug:

```
[drw_sale_items_list category="combos" limit="12" columns="4"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| `limit` | `12` | Max products to show (max 48) |
| `columns` | `4` | Grid columns (max 6) |
| `category` | _(all)_ | Comma-separated category slugs |
| `ids` | _(all)_ | Comma-separated product IDs |
| `class` | _(none)_ | Extra CSS class on the wrapper |

**Supported shortcode aliases:** `[drw_sale_items_list]`, `[awdr_sale_items_list]`, `[on_sale]`

### Percentage Badge Markup

Active discount rules automatically inject a badge on product cards:

```html
<div class="sale-perc">-15 %</div>
```

Style it freely via your theme CSS.

---

## Project Structure

```
discount-rules-woo/
├── discount-rules-woo.php      # Plugin bootstrap & constants
├── src/
│   ├── Router.php              # Hook registration orchestrator
│   ├── Controllers/
│   │   ├── AdminController.php # WP Admin menu & asset enqueue
│   │   ├── ApiController.php   # REST API endpoints (drw/v1/*)
│   │   ├── CartController.php  # Cart/checkout price hooks
│   │   ├── CatalogController.php # Shop loop & product page hooks
│   │   ├── RulesEngine.php     # Core rule evaluation engine
│   │   ├── ShortcodeController.php # [drw_sale_items_list] shortcode
│   │   └── Updater.php         # GitHub auto-update integration
│   ├── Models/
│   │   ├── Database.php        # Table creation & migrations
│   │   └── RuleModel.php       # CRUD + payload sanitization
│   ├── Conditions/             # One class per condition type
│   └── Adjustments/            # One class per discount type
├── assets/
│   ├── css/admin-style.css
│   └── js/admin-app.js         # Compiled React admin app
└── tests/                      # Integration test scripts
```

---

## REST API

The plugin exposes a REST API under the `drw/v1` namespace, protected by the `manage_woocommerce` capability.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/drw/v1/rules` | List all rules |
| `POST` | `/drw/v1/rules` | Create a rule |
| `GET` | `/drw/v1/rules/{id}` | Get a single rule |
| `DELETE` | `/drw/v1/rules/{id}` | Delete a rule (soft delete) |
| `GET` | `/drw/v1/products` | Search products (admin async lookup) |

Authentication uses the standard WordPress REST API nonce (`X-WP-Nonce` header with a `wp_rest` nonce).

---

## Contributing

Contributions are welcome. Please open an issue first to discuss significant changes.

1. Fork the repository.
2. Create a feature branch: `git checkout -b feature/my-feature`.
3. Commit your changes.
4. Push and open a Pull Request targeting `main`.

---

## Changelog

### 1.5.0
- Rebranded the visible plugin name to **OmniDiscount** with a dedicated top-level admin menu
- Added promotions engine (coupons & promotions) backed by a dedicated `drw_promos` table, with automatic migration from the legacy options storage
- Added analytics page (discount totals, orders with discounts, free-shipping orders)
- Added rules import/export (admin page + REST endpoints)
- Spanish admin interface copy

### 1.2.1
- Fixed scheduled rules and dynamic sale shortcode discovery
- Added advanced targeting exclusions and coupon schedule windows
- Added sale items shortcode with percentage badges
- Added scalable async product search in admin
- Improved auto-updater to prefer uploaded release ZIP assets

### 1.0.0
- Initial release

---

## License

[GPLv3 or later](https://www.gnu.org/licenses/gpl-3.0.html) — free to use, modify, and distribute.

---

*Developed by [Bywuilgonzalez.com](https://bywuilgonzalez.com)*
