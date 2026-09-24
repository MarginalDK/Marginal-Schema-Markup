# Marginal Schema Markup: development guide

WordPress plugin (PHP 7.4+, WP 6.0+) for JSON-LD in `<head>`. See README.md for features and the release process.

## Always, after every code change
```bash
composer check
```
(= `phpcs` + the whole PHPUnit suite). The first time, run `composer install && composer install-wp`. Nothing is done before everything is green.

## Rules
- **Security first.** New input, output, endpoints (admin-post/AJAX/REST) or files require new tests in `tests/security/`. Never remove or weaken a security test to make it pass. Fix the code instead.
- JSON-LD may only be output through `Marginal_Schema_Json::build_script_tag()` (decode → re-encode with `JSON_HEX_TAG`, always `<script type="application/ld+json">`).
- Handlers: `current_user_can()` + nonce check before anything else. Superglobals: always `wp_unslash()` + sanitize.
- Robustness: frontend code in `try/catch ( \Throwable )` with `Marginal_Schema_Logger`. Only stable WordPress APIs, no external libraries in the plugin. The main file must only use old PHP syntax.
- New files in `includes/`/`assets/` must be added to `test_only_known_files_are_shipped` and to the zip whitelist in `.github/workflows/release.yml` where relevant.
- Keep header `Version` and `MARGINAL_SCHEMA_VERSION` identical.
- The UI is in Danish.
