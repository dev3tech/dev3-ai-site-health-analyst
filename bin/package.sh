#!/usr/bin/env bash
# Builds a clean release zip (dist/dev3-ai-site-health-analyst.zip) and the Envato/CodeCanyon bundle
# (dist/dev3-ai-site-health-analyst-envato.zip: plugin zip + documentation + licensing notes).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="dev3-ai-site-health-analyst"
VERSION="$(grep -E '^\s*\*\s*Version:' "$ROOT/$SLUG.php" | head -1 | awk '{print $NF}')"
DIST="$ROOT/dist"
STAGE="$DIST/stage/$SLUG"

cd "$ROOT"
echo "Building $SLUG $VERSION"
npm run build >/dev/null

rm -rf "$DIST"
mkdir -p "$STAGE"

# Runtime files only.
cp "$SLUG.php" uninstall.php readme.txt "$STAGE/"
cp -R includes assets build languages "$STAGE/"
cp LICENSE.txt "$STAGE/" 2>/dev/null || true
find "$STAGE" \( -name '.DS_Store' -o -name '*.map' \) -delete

# Refuse to ship anything that looks like a secret or dev-only file.
if grep -rIl -E 'AIza[0-9A-Za-z_-]{20,}' "$STAGE" >/dev/null; then
	echo "Refusing to package: an API key-like string was found." >&2
	exit 1
fi
for forbidden in tests src node_modules vendor .wp-env.json composer.json package.json; do
	[ -e "$STAGE/$forbidden" ] && { echo "Unexpected $forbidden in package" >&2; exit 1; }
done

( cd "$DIST/stage" && zip -qr "../$SLUG.zip" "$SLUG" )

# Envato bundle.
BUNDLE="$DIST/stage/envato"
mkdir -p "$BUNDLE/Documentation"
cp "$DIST/$SLUG.zip" "$BUNDLE/"
cp -R docs/. "$BUNDLE/Documentation/"
rm -f "$BUNDLE/Documentation/SPEC.md" # Internal competitor teardown, never shipped.
cp docs/licensing.txt "$BUNDLE/Licensing.txt"
( cd "$BUNDLE" && zip -qr "../../$SLUG-envato.zip" . )

if unzip -l "$DIST/$SLUG-envato.zip" | grep -q "SPEC.md"; then echo "SPEC.md leaked into the bundle" >&2; exit 1; fi
if unzip -l "$DIST/$SLUG.zip" | grep -qE "SPEC.md|tests/|seed-demo"; then echo "Dev files leaked into the plugin zip" >&2; exit 1; fi
rm -rf "$DIST/stage"
echo "dist/$SLUG.zip ($(du -h "$DIST/$SLUG.zip" | cut -f1))"
echo "dist/$SLUG-envato.zip ($(du -h "$DIST/$SLUG-envato.zip" | cut -f1))"
