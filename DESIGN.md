# Design

## Visual Theme
Standard WordPress Admin style integrated with custom modern visual enhancements. It utilizes clean surfaces, subtle shadows, and professional gradients.

## Color Palette
Using OKLCH composition:
*   **Background (Canvas)**: `oklch(1 0 0)` - Pure white cards on `oklch(0.98 0 0)` WordPress background.
*   **Brand Primary (Accent)**: `oklch(0.60 0.18 250)` - WordPress Blue.
*   **Success (Active)**: `oklch(0.48 0.15 145)` - Active green badge.
*   **Ink/Text (Primary)**: `oklch(0.25 0.02 240)` - Off-black slate.
*   **Border/Muted**: `oklch(0.92 0.01 240)` - Light gray separators.

## Typography
*   **Font Stack**: `-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif` (native system fonts).
*   **Scale**: Heading at `24px` bold, Cards Title at `16px` semi-bold, Meta labels at `12px`.

## Layout
*   Dashboard uses flex-col structure with gap-4.
*   Editor fields use responsive flex-row grids.
*   Nested conditions use indent spacing with borders.

## Component Patterns
*   **Rule Cards**: Custom flexbox containers with scale hover transitions.
*   **Tier Tables**: Clean bordered table matrix for bulk breaks.
