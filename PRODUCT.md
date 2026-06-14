# Product

## Register

product

## Users
WooCommerce store owners, administrators, and shop managers who need to configure dynamic pricing, bulk tiers, and condition-based discount promotions without complicating their checkout flow.

## Product Purpose
To provide a 100% functional, clean, unified, and high-performance discount rules engine for WooCommerce, avoiding the bloated, clunky, and split codebase architecture of legacy plugins.

## Brand Personality
*   **Professional**: Deep WooCommerce integration and seamless WordPress admin appearance.
*   **Robust**: Predictable rules calculation, thread-safe hooks, and reliable database transactions.
*   **Confident**: Sleek user experience with clean layout and fast loading screens.

## Anti-references
*   **Cluttered/Bloated Legacy Panels**: Legacy interfaces filled with advertisements, slow jQuery scripts, and confusing core/pro version divisions.
*   **Cart Latency**: Discount calculations that add hundreds of milliseconds to page response and checkout operations.

## Design Principles
1.  **Seamless Integration**: The interface must blend with the default WordPress Gutenberg look using `@wordpress/components`.
2.  **Performance First**: Calculations must be cached per request and execute in under 50ms.
3.  **One Codebase**: Combined free and premium features in a modular, clean strategy layout.

## Accessibility & Inclusion
*   Strict WCAG 2.1 AA contrast compliance on all settings and rule grids.
*   Screen reader compatibility for rule builder forms.
*   Keyboard navigation support for tiered table inputs.
