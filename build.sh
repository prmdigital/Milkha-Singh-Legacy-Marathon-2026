#!/usr/bin/env bash
#
# Builds the folder you upload to Hostinger.
#
#   bash build.sh
#
# Everything in dist/ goes inside public_html. Nothing is minified or bundled:
# the site is hand-written HTML/CSS/JS and a few PHP endpoints, so a copy is
# genuinely all a build needs to be.
#
# The one job this script must never get wrong is secrets. api/config.php holds
# live Razorpay keys and the database password if you ever created it for local
# testing, so it is deleted from the build and the result is checked before the
# zip is written.

set -euo pipefail

cd "$(dirname "$0")"

OUT=dist
ZIP=milkha-singh-marathon-site.zip

echo "Cleaning $OUT ..."
rm -f "$ZIP"
# Empty the folder rather than deleting it: on Windows an open Explorer window
# or a running preview server holds a handle on the directory itself and
# `rm -rf dist` fails with "Device or resource busy".
mkdir -p "$OUT"
find "$OUT" -mindepth 1 -delete

echo "Copying site ..."
cp index.html privacy-policy.html refund-policy.html terms-conditions.html \
   robots.txt sitemap.xml "$OUT"/
cp -r assets images api admin "$OUT"/

# ---- Strip anything that must not be published -----------------------------

# Local-only credentials. On the server the real file lives ABOVE public_html.
rm -f "$OUT/api/config.php"

# The sample is harmless but there is no reason to publish the shape of the
# config either.
rm -f "$OUT/api/config.sample.php"

# SQL schemas are run once by hand in phpMyAdmin; they are not web content.
rm -f "$OUT"/api/*.sql

# Editor and OS leftovers.
find "$OUT" -name '.DS_Store' -o -name 'Thumbs.db' | xargs -r rm -f

# ---- Refuse to ship a secret ------------------------------------------------

if [ -f "$OUT/api/config.php" ]; then
  echo "REFUSING TO BUILD: api/config.php is still in $OUT" >&2
  exit 1
fi

if grep -rlE "rzp_live_|rzp_test_[A-Za-z0-9]{8,}" "$OUT" 2>/dev/null | grep -v config.sample; then
  echo "REFUSING TO BUILD: a Razorpay key appears in the build output above." >&2
  exit 1
fi

# ---- Package ----------------------------------------------------------------

if command -v zip >/dev/null 2>&1; then
  (cd "$OUT" && zip -qr "../$ZIP" .)
  echo "Wrote $ZIP"
elif command -v powershell.exe >/dev/null 2>&1; then
  # Git Bash on Windows ships no zip; PowerShell is always there.
  powershell.exe -NoProfile -Command \
    "Compress-Archive -Path '$OUT/*' -DestinationPath '$ZIP' -Force" >/dev/null
  echo "Wrote $ZIP"
else
  echo "No zip tool found — upload the $OUT folder itself."
fi

echo ""
echo "Build complete."
echo "  Files : $(find "$OUT" -type f | wc -l)"
echo "  Size  : $(du -sh "$OUT" | cut -f1)"
echo ""
echo "Upload the CONTENTS of $OUT/ into public_html."
echo "Remember: marathon-config.php belongs ONE LEVEL ABOVE public_html."
