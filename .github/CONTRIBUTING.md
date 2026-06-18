# Contributing

Thank you for your interest in contributing to this plugin.

## Before You Start

- Open an issue to discuss significant changes before writing code.
- For small bug fixes, a PR directly is fine.
- Check existing issues and PRs to avoid duplicating work.

## Setup

```bash
git clone https://github.com/bywuilgonzalez-co/discount-rules-woo.git
cd discount-rules-woo
```

No build step is required for PHP files. The compiled React admin app (`assets/js/admin-app.js`) is committed to the repository.

## Guidelines

### PHP Code
- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/).
- All user input must be sanitized (`sanitize_text_field`, `absint`, etc.).
- All HTML output must be escaped (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).
- Use `$wpdb->prepare()` for any query that includes a variable.
- Every PHP file must begin with `if (!defined('ABSPATH')) exit;`.

### Commits
- Use clear, imperative commit messages: `Fix bulk discount not applying on variable products`.
- One logical change per commit.

### Pull Requests
- Target the `main` branch.
- Fill in the PR template completely.
- All CI checks must pass before a PR can be merged.
- At least one review approval is required.

## What We Won't Merge

- Code that introduces SQL injection, XSS, or other OWASP Top 10 vulnerabilities.
- Direct `$_POST`/`$_GET` access without sanitization.
- `eval()`, `exec()`, `shell_exec()`, `unserialize()` on user input.
- External HTTP requests not using `wp_remote_get()` / `wp_remote_post()`.
- Breaking changes to the REST API without a version bump.

## Reporting Bugs

Use the [Bug Report](.github/ISSUE_TEMPLATE/bug_report.md) issue template. Include your WordPress version, WooCommerce version, PHP version, and steps to reproduce.
