# Marginal Schema Markup

WordPress plugin from Marginal that outputs JSON-LD schema markup in `<head>`:

- **Global JSON-LD** (e.g. `Organization`, `LocalBusiness`, `WebSite`) on every page on the site.
- **Page-specific JSON-LD** on individual Pages, output at the very end of `<head>`.

All output is wrapped in `<script type="application/ld+json">`. The plugin updates itself from releases on GitHub and keeps a simple error log.

**Requirements:** WordPress 6.0+ and PHP 7.4+.

---

## How to use it

### Installation
1. Download `marginal-schema-markup.zip` from the latest [release](https://github.com/MarginalDK/Marginal-Schema-Markup/releases).
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose the zip file and activate it.

> Use the zip file from the release, *not* GitHub's green "Download ZIP" button. That gives the folder the wrong name.

### Global JSON-LD
**Settings → Schema Markup → Global JSON-LD.** Paste the JSON-LD and save. You can paste plain JSON or the whole `<script type="application/ld+json">…</script>` block. If you paste several blocks, they are merged into a list.

### JSON-LD on a single page
Open a page under **Pages**. Below the editor there is a **Schema markup (JSON-LD)** box. Paste the JSON-LD and update the page.

In both places the field shows live whether the JSON is valid, and **Format JSON** tidies up the indentation. Invalid JSON is still saved, so nothing gets lost, but it is **not** output on the site. You get a warning instead, and the error is written to the log.

### The log
**Settings → Schema Markup → Log** shows errors such as:
- invalid JSON-LD on a page, with a link to the page
- a theme/template that never calls `wp_head()`, so the JSON-LD cannot be output
- failed update checks against GitHub

Identical errors are merged into one line (with a count). The log keeps the latest 100 lines.

### Updates
New versions show up under **Plugins** and **Dashboard → Updates** like any other plugin. WordPress checks automatically about twice a day, and the plugin caches GitHub's response for 6 hours. Use **Settings → Schema Markup → Updates → Check for updates now** to check immediately. Automatic updates can be turned on per site from the plugin list.

---

## Security

The plugin is built so that nobody can use it as a way into the site:

| Risk | Protection |
|---|---|
| XSS through JSON-LD (e.g. `</script><script>…`) | Input is **never** output raw. It is always decoded and re-encoded with `JSON_HEX_TAG`, so `<` and `>` can never appear in the output. There is an extra safety net on top. |
| Unauthorized changes | Global settings require `manage_options` (administrator). The page field requires permission to edit that specific page. |
| CSRF | All forms and actions are protected by WordPress nonces. |
| Data leaks | Password-protected pages and drafts output nothing. The data is not exposed in the REST API. The log is stored in the database (never as a publicly reachable file) and does not store query strings. |
| Hijacked updates | Packages are only fetched over HTTPS from this repository's own releases. Any other URL is rejected. `Update URI` prevents WordPress.org from "taking over" the plugin. |
| Broken update packages | The package is checked before the old version is removed. If the main file is missing, the update is aborted and the current version is kept. |
| Crash on WordPress/PHP updates | Only stable, core WordPress APIs. No external libraries. PHP/WP version and file checks run before anything loads. All output code is wrapped so that errors are logged instead of breaking the page. |

---

## Maintenance and development

### Structure
```
marginal-schema-markup.php   Bootstrap: version checks, loads classes
uninstall.php                Removes all data when the plugin is deleted
includes/
  class-marginal-schema-json.php      Validation + the ONLY place <script> is built
  class-marginal-schema-output.php    Output in <head>
  class-marginal-schema-metabox.php   Field on Pages
  class-marginal-schema-admin.php     Settings page (Global / Log / Updates)
  class-marginal-schema-logger.php    Error log
  class-marginal-schema-updater.php   Updates from GitHub releases
assets/                      Admin JS/CSS (no dependencies)
tests/                       Test suite (not shipped in the zip)
```

### Filters for developers
```php
// Show the field on other post types too (default: only 'page').
add_filter( 'marginal_schema_post_types', fn( $types ) => [ 'page', 'post' ] );

// Choose which post's JSON-LD is used on the current page.
add_filter( 'marginal_schema_current_post_id', fn( $id ) => $id );
```

### Run the tests locally
The tests run against a real WordPress. Locally they use SQLite, so no database server is needed.

```bash
composer install
```
```bash
composer install-wp
```
```bash
composer check
```

`composer check` runs PHPCS (security + PHP 7.4 compatibility) and the whole PHPUnit suite. You can also run just one part: `vendor/bin/phpunit --testsuite security`.

The tests are split into:
- `tests/functionality/`: every function (JSON, output, field, settings, log, updater, install/uninstall).
- `tests/security/`: XSS attack strings, access control (roles, nonces, REST), and a static scan of the code for backdoors and dangerous functions (`eval`, `base64_decode`, `exec`, unknown external hosts, missing `ABSPATH` checks, and so on).

**Rule:** every new feature must have tests, and every new input/output must have security tests. If you add a file to `includes/` or `assets/`, you must also add it to `test_only_known_files_are_shipped` (deliberately, so new files get reviewed).

### GitHub Actions
- **CI** (`.github/workflows/ci.yml`) runs on every push and pull request: syntax check on PHP 7.4–8.4, PHPCS, and all tests against MySQL on several PHP/WordPress combinations.
- **Release** (`.github/workflows/release.yml`) runs when a release is published: it runs all of CI first, then builds and attaches the zip file.

---

## Releasing a new version

1. Bump the version number in **two places** in `marginal-schema-markup.php`:
   ```php
    * Version:           1.1.0
   ```
   ```php
   define( 'MARGINAL_SCHEMA_VERSION', '1.1.0' );
   ```
   If the new version needs a newer PHP/WordPress, also update `Requires PHP` / `Requires at least` **and** the constants `MARGINAL_SCHEMA_MIN_PHP` / `MARGINAL_SCHEMA_MIN_WP`. WordPress then blocks the update on sites that do not meet the requirements.
2. Run `composer check` locally, commit and push. Wait for CI to go green.
3. On GitHub: **Releases → Draft a new release**.
   - **Tag:** `v1.1.0` (must match the version exactly, with a `v` in front).
   - **Description:** what changed. This is shown under "View details" in WordPress.
   - Click **Publish release** (not "pre-release").
4. The release workflow runs the tests and attaches `marginal-schema-markup.zip`. Check under **Actions** that it went green and that the zip file is on the release.
5. Within ~12 hours the update shows up on all sites (or right away via "Check for updates now").

If something fails in step 4, no zip file is attached, and the sites are never offered the broken version. Fix the problem, delete the release and the tag, and start again.

> **Note:** The updater requires the repository to be **public**. With a private repository, GitHub answers "404" and the log shows *"No published release found (or the repository is private)"*.

### Hard rules (so sites never crash)
- `marginal-schema-markup.php` must only use simple PHP syntax (no `fn`, `match`, `?->` etc.) so the version check can show a friendly message on old servers. This is tested automatically.
- Use only official WordPress functions. No Composer packages in the plugin itself (Composer is only used for tests).
- Everything that runs on the frontend must be wrapped in `try { … } catch ( \Throwable $e )` and log the error.
