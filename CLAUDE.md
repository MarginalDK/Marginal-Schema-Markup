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
- Only administrators (`Marginal_Schema_Admin::CAPABILITY`) may use the plugin: the settings page **and** the page field. The meta key's `auth_callback` stays `__return_false`, so XML-RPC/REST/"Custom Fields" can't read or write it.
- The page field's nonce is bound to the page (`Marginal_Schema_Metabox::nonce_action( $post_id )`), and `$_POST['post_ID']` must match. Never loosen this, or sync/translation plugins can overwrite other pages.
- Robustness: frontend code in `try/catch ( \Throwable )` with `Marginal_Schema_Logger`. Only stable WordPress APIs, no external libraries in the plugin. The main file must only use old PHP syntax.
- No named functions at the top level of `marginal-schema-markup.php` or `uninstall.php` (use an `if` block or closures). Otherwise a second copy causes "Cannot redeclare function".
- No regular expressions that can backtrack on user input (keep `strip_script_wrappers()` linear, in PHP **and** JS). Check sizes (`Marginal_Schema_Json::check_size()`) before any processing.
- Truncate text with `Marginal_Schema_Json::truncate_utf8()`, never `substr()`: a cut æ/ø/å makes the database reject the value.
- New release: bump the version in the header **and** `MARGINAL_SCHEMA_VERSION`, and add a Danish section to `CHANGELOG.md`.
- New files in `includes/`/`assets/` must be added to `test_only_known_files_are_shipped` and to the zip whitelist in `.github/workflows/release.yml` where relevant.
- Keep header `Version` and `MARGINAL_SCHEMA_VERSION` identical.
- The UI is in Danish.
