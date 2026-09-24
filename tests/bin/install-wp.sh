#!/usr/bin/env bash
#
# Installerer WordPress core + WordPress' officielle test-bibliotek til test-suiten.
#
# Brug:
#   tests/bin/install-wp.sh [wp-version] [db-engine]
#
#   wp-version  "latest" (standard) eller fx "6.4"
#   db-engine   "sqlite" (standard, kræver ingen database-server) eller "mysql"
#
# Alt installeres i .wp-tests/ i projektmappen (ignoreres af git).

set -euo pipefail

WP_VERSION="${1:-latest}"
DB_ENGINE="${2:-sqlite}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BASE="${WP_TESTS_BASE_DIR:-$ROOT/.wp-tests}"
CORE_DIR="$BASE/wordpress"
LIB_DIR="$BASE/lib"
SQLITE_VERSION="v3.0.2"

mkdir -p "$BASE"

# Find konkret versionsnummer.
if [ "$WP_VERSION" = "latest" ]; then
	WP_VERSION="$(curl -fsSL https://api.wordpress.org/core/version-check/1.7/ | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["offers"][0]["version"];')"
fi
WP_MINOR="$(echo "$WP_VERSION" | cut -d. -f1,2)"
echo "WordPress $WP_VERSION (test-bibliotek $WP_MINOR.*), database: $DB_ENGINE"

# 1) WordPress core.
if [ ! -f "$CORE_DIR/wp-includes/version.php" ] || ! grep -q "wp_version = '$WP_VERSION'" "$CORE_DIR/wp-includes/version.php"; then
	rm -rf "$CORE_DIR"
	mkdir -p "$CORE_DIR"
	curl -fsSL "https://wordpress.org/wordpress-$WP_VERSION.tar.gz" | tar -xz -C "$CORE_DIR" --strip-components=1
fi

# 2) Test-biblioteket (wp-phpunit er en spejling af wordpress-develop/tests/phpunit).
if [ ! -f "$LIB_DIR/vendor/wp-phpunit/wp-phpunit/includes/bootstrap.php" ] || [ "$(cat "$LIB_DIR/.wp-minor" 2>/dev/null)" != "$WP_MINOR" ]; then
	rm -rf "$LIB_DIR"
	mkdir -p "$LIB_DIR"
	echo '{}' > "$LIB_DIR/composer.json"
	composer require --no-interaction --quiet --working-dir="$LIB_DIR" "wp-phpunit/wp-phpunit:$WP_MINOR.*"
	echo "$WP_MINOR" > "$LIB_DIR/.wp-minor"
fi

# 3) Database.
rm -f "$CORE_DIR/wp-content/db.php"
if [ "$DB_ENGINE" = "sqlite" ]; then
	SQLITE_DIR="$BASE/sqlite-database-integration"
	if [ ! -f "$SQLITE_DIR/db.copy" ]; then
		rm -rf "$SQLITE_DIR"
		TMP_ZIP="$BASE/sqlite.zip"
		curl -fsSL -o "$TMP_ZIP" "https://github.com/WordPress/sqlite-database-integration/releases/download/$SQLITE_VERSION/plugin-sqlite-database-integration.zip"
		unzip -q -o "$TMP_ZIP" -d "$BASE"
		mv "$BASE/plugin-sqlite-database-integration" "$SQLITE_DIR"
		rm -f "$TMP_ZIP"
	fi
	sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SQLITE_DIR#" -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
		"$SQLITE_DIR/db.copy" > "$CORE_DIR/wp-content/db.php"
	mkdir -p "$BASE/database"
fi

# 4) Pluginnet "installeres" som symlink, præcis som mappen hedder på et rigtigt site.
mkdir -p "$BASE/plugins"
ln -sfn "$ROOT" "$BASE/plugins/marginal-schema-markup"

echo "$DB_ENGINE" > "$BASE/.db-engine"
echo "Klar. Kør testene med: composer test"
