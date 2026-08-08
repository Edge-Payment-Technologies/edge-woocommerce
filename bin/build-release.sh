#!/usr/bin/env bash
#
# Build the distributable plugin ZIP.
#
# Produces a tree with production-only Composer dependencies, all of them
# namespace-prefixed, and the built JavaScript. The prefixing is the point:
# WordPress loads every plugin into one process, so shipping an unprefixed
# Guzzle would put this plugin in a fight with any other plugin that vendors a
# different version of it.
#
# Usage: bin/build-release.sh [output-dir]

set -euo pipefail

PLUGIN_SLUG="edge-woocommerce"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$ROOT/dist}"
STAGE="$OUT/$PLUGIN_SLUG"
SCOPER="$ROOT/bin/php-scoper.phar"

cd "$ROOT"

if [[ ! -f "$SCOPER" ]]; then
  echo "error: $SCOPER is missing." >&2
  echo "  curl -sL -o bin/php-scoper.phar \\" >&2
  echo "    https://github.com/humbug/php-scoper/releases/latest/download/php-scoper.phar" >&2
  exit 1
fi

echo "==> Cleaning $OUT"
rm -rf "$OUT"
mkdir -p "$STAGE"

echo "==> Installing production dependencies"
# --no-dev matters: without it PHPUnit and the coding standards end up in the
# shipped ZIP.
composer install --no-dev --optimize-autoloader --quiet

echo "==> Building JavaScript"
npx wp-scripts build

echo "==> Prefixing namespaces"
# Scope the plugin sources and vendor together, so references in our own code
# are rewritten to match the prefixed classes.
php "$SCOPER" add-prefix \
  --config="$ROOT/scoper.inc.php" \
  --output-dir="$STAGE" \
  --force \
  --quiet \
  edge-gateway.php includes vendor

echo "==> Copying build output and metadata"
cp -R "$ROOT/assets" "$STAGE/assets"
for f in readme.txt README.md LICENSE; do
  [[ -f "$ROOT/$f" ]] && cp "$ROOT/$f" "$STAGE/$f"
done
[[ -d "$ROOT/languages" ]] && cp -R "$ROOT/languages" "$STAGE/languages"

echo "==> Regenerating the autoloader inside the build"
# Scoping rewrites vendor/composer/* along with everything else, which leaves the
# generated autoloader referencing a prefixed ClassLoader that no longer matches
# the shipped files. Regenerating inside the staged tree fixes that, and it needs
# a composer.json to work from.
cp "$ROOT/composer.json" "$STAGE/composer.json"
composer dump-autoload --no-dev --classmap-authoritative --working-dir="$STAGE" --quiet
# Not part of the plugin; only needed for the dump above.
rm -f "$STAGE/composer.json" "$STAGE/composer.lock"

echo "==> Verifying"
fail=0

# The SDK must be a real directory, not a path-repository symlink: a symlink
# would resolve to a checkout that does not exist on the target machine.
if [[ -L "$STAGE/vendor/edge-payment-technologies/edge-php-sdk" ]]; then
  echo "  FAIL: the SDK is a symlink; build from a clean checkout without composer-local.json" >&2
  fail=1
fi

if grep -rql "PHPUnit\\\\Framework" "$STAGE/vendor" 2>/dev/null; then
  echo "  FAIL: development dependencies present in the build" >&2
  fail=1
fi

# Every vendored Guzzle class must be prefixed, or the collision this build
# exists to prevent is still there.
if grep -rq "^namespace GuzzleHttp" "$STAGE/vendor" 2>/dev/null; then
  echo "  FAIL: unprefixed GuzzleHttp namespace remains" >&2
  fail=1
fi

# The composer.json constraint alone never proves which SDK resolved - dev-main
# still points at the pre-v2 release. Check the shipped code, not the manifest.
if ! php -r '
  require "'"$STAGE"'/vendor/autoload.php";
  $c = "EdgePayments\\EdgeWoocommerce\\Vendor\\Edge\\Client";
  exit( class_exists($c) && method_exists($c, "confirm") ? 0 : 1 );
' 2>/dev/null; then
  echo "  FAIL: the built tree does not contain the Edge v2 SDK (no Client::confirm())" >&2
  echo "        composer.json must resolve a tagged v2 release, not dev-main." >&2
  fail=1
fi

for required in "edge-gateway.php" "includes" "vendor/autoload.php" "assets/js/frontend/blocks.js"; do
  if [[ ! -e "$STAGE/$required" ]]; then
    echo "  FAIL: missing $required" >&2
    fail=1
  fi
done

if [[ "$fail" -ne 0 ]]; then
  echo "==> Build FAILED verification" >&2
  exit 1
fi

VERSION="$(grep -m1 '^ \* Version:' "$STAGE/edge-gateway.php" | sed 's/.*Version: *//')"
ZIP="$OUT/${PLUGIN_SLUG}-${VERSION}.zip"

echo "==> Packaging $ZIP"
( cd "$OUT" && zip -qr "$(basename "$ZIP")" "$PLUGIN_SLUG" )

echo "==> Done: $ZIP ($(du -h "$ZIP" | cut -f1))"
